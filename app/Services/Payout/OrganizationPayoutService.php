<?php

namespace App\Services\Payout;

use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\TransactionLog;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes live organization balances from the current donation formula JSON
 * and the append-only payout ledger (Option A: no donation-time snapshots).
 */
class OrganizationPayoutService
{
    public function __construct(
        private OrganizationPayoutDestinationService $destinationGuard,
    ) {}

    /**
     * List every allocatable (formula_id + organization) pair with its
     * live owed / paid / balance amounts.
     *
     * @return Collection<int, array>
     */
    public function payableAllocations(?string $formulaUuid = null): Collection
    {
        $formulas = DonationFormula::query()
            ->when($formulaUuid, fn ($q) => $q->where('uuid', $formulaUuid))
            ->with('article:id,uuid,slug,title')
            ->get();

        $formulaIds = $formulas->pluck('id');

        // Batch aggregate donations per formula
        $donationSums = DB::table('donations')
            ->whereIn('donation_formula_id', $formulaIds)
            ->where('status', 'completed')
            ->groupBy('donation_formula_id')
            ->selectRaw('donation_formula_id, SUM(amount) as total')
            ->pluck('total', 'donation_formula_id');

        // Batch aggregate payouts per (formula_id, organization_key)
        $payoutSums = DB::table('organization_payouts')
            ->whereIn('donation_formula_id', $formulaIds)
            ->groupBy('donation_formula_id', 'organization_key')
            ->selectRaw('donation_formula_id, organization_key, SUM(amount) as total')
            ->get()
            ->groupBy('donation_formula_id');

        // Batch currency lookup per formula
        $currencies = DB::table('donations')
            ->whereIn('donation_formula_id', $formulaIds)
            ->where('status', 'completed')
            ->groupBy('donation_formula_id')
            ->selectRaw('donation_formula_id, MIN(currency) as currency')
            ->pluck('currency', 'donation_formula_id');

        $allocations = collect();

        foreach ($formulas as $formula) {
            $totalDonated = (float) ($donationSums->get($formula->id) ?? 0);
            $currency = (string) ($currencies->get($formula->id) ?? 'usd');
            $formulaPayouts = collect($payoutSums->get($formula->id, collect()));

            foreach ($this->normalizeItems($formula->formula) as $item) {
                $owed = round($totalDonated * ($item['percentage'] / 100), 2);
                $paid = round((float) ($formulaPayouts->firstWhere('organization_key', $item['key'])?->total ?? 0), 2);
                $balance = round($owed - $paid, 2);

                if ($owed <= 0 && $paid <= 0) {
                    continue;
                }

                $allocations->push([
                    'formula_id' => $formula->id,
                    'formula_uuid' => $formula->uuid,
                    'formula_name' => $formula->name,
                    'article' => $formula->article ? [
                        'uuid' => $formula->article->uuid,
                        'slug' => $formula->article->slug,
                        'title' => $formula->article->title,
                    ] : null,
                    'organization_name' => $item['name'],
                    'organization_key' => $item['key'],
                    'percentage' => $item['percentage'],
                    'currency' => $currency,
                    'owed' => $owed,
                    'paid' => $paid,
                    'balance' => $balance,
                ]);
            }
        }

        return $allocations;
    }

    /**
     * Live balance for a single allocation.
     */
    public function balance(DonationFormula $formula, string $orgName): object
    {
        return $this->owedFor($formula, OrganizationPayout::makeKey($orgName));
    }

    /**
     * Transactional payout create with row lock to prevent overpay races.
     *
     * @throws Exception when validation / overpay fails.
     */
    public function createPayout(array $data, int $actorId): OrganizationPayout
    {
        $formula = DonationFormula::whereKey($data['donation_formula_id'])->first();
        if (! $formula) {
            throw new Exception('Donation formula not found.');
        }

        $orgName = trim($data['organization_name']);
        $key = OrganizationPayout::makeKey($orgName);

        // Destination guard runs outside the main transaction so that
        // payout.blocked audit rows are not rolled back on failure.
        $organization = $this->destinationGuard->resolve($formula, $orgName);

        $items = $this->normalizeItems($formula->formula);
        $match = collect($items)->first(fn ($i) => $i['key'] === $key);
        if (! $match) {
            throw new Exception('Organization is not allocated in this formula (anymore).');
        }

        $balance = $this->owedFor($formula, $key);
        if ($balance->currency && $data['currency'] !== $balance->currency) {
            throw new Exception("Currency mismatch: allocation currency is {$balance->currency}.");
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new Exception('Payout amount must be greater than 0.');
        }
        if ($amount > $balance->balance + 0.009) {
            throw new Exception(sprintf(
                'Payout of %.2f exceeds the live balance of %.2f.',
                $amount,
                $balance->balance
            ));
        }

        return DB::transaction(function () use ($data, $actorId, $formula, $orgName, $key, $amount, $balance, $organization) {
            // Re-fetch with lock inside the transaction to prevent overpay races.
            $lockedFormula = DonationFormula::whereKey($formula->id)->lockForUpdate()->first();
            if (! $lockedFormula) {
                throw new Exception('Donation formula not found.');
            }

            $liveBalance = $this->owedFor($lockedFormula, $key);
            if ($amount > $liveBalance->balance + 0.009) {
                throw new Exception(sprintf(
                    'Payout of %.2f exceeds the live balance of %.2f.',
                    $amount,
                    $liveBalance->balance
                ));
            }

            $payout = OrganizationPayout::create([
                'donation_formula_id' => $formula->id,
                'organization_name' => $orgName,
                'organization_key' => $key,
                'amount' => $amount,
                'currency' => $data['currency'],
                'type' => $amount >= $liveBalance->balance - 0.009 ? 'full' : 'partial',
                'status' => 'paid',
                'paid_at' => now(),
                'actor_id' => $actorId,
                'note' => $data['note'] ?? null,
            ]);

            TransactionLog::record(
                'payout.created',
                $payout,
                before: [
                    'balance_before' => $balance->balance,
                    'currency' => $balance->currency,
                ],
                after: [
                    'amount' => $amount,
                    'destination_paypal_email' => $organization->paypal_email,
                    'destination_organization_id' => $organization->id,
                ],
                actorId: $actorId,
            );

            return $payout;
        });
    }

    /**
     * Payout history rows for an allocation (newest first).
     */
    public function history(DonationFormula $formula, string $orgName): Collection
    {
        return OrganizationPayout::query()
            ->where('donation_formula_id', $formula->id)
            ->where('organization_key', OrganizationPayout::makeKey($orgName))
            ->with('actor:id,uuid,username')
            ->latest('paid_at')
            ->get();
    }

    private function owedFor(DonationFormula $formula, string $key): object
    {
        $items = $this->normalizeItems($formula->formula);
        $item = collect($items)->first(fn ($i) => $i['key'] === $key);

        $paid = (float) OrganizationPayout::query()
            ->where('donation_formula_id', $formula->id)
            ->where('organization_key', $key)
            ->sum('amount');

        if (! $item) {
            // Organization no longer exists in the current formula: still
            // show what was paid, but nothing more is owed.
            return (object) [
                'owed' => $paid,
                'paid' => $paid,
                'balance' => 0.0,
                'currency' => $this->currencyFor($formula),
            ];
        }

        $total = (float) Donation::query()
            ->where('donation_formula_id', $formula->id)
            ->where('status', 'completed')
            ->sum('amount');

        $owed = round($total * ($item['percentage'] / 100), 2);

        return (object) [
            'owed' => $owed,
            'paid' => round($paid, 2),
            'balance' => round($owed - $paid, 2),
            'currency' => $this->currencyFor($formula),
        ];
    }

    private function normalizeItems(?array $formula): array
    {
        return collect($formula ?? [])
            ->filter(fn ($row) => is_array($row) && ! empty($row['organization']))
            ->map(fn ($row) => [
                'name' => (string) $row['organization'],
                'key' => OrganizationPayout::makeKey((string) $row['organization']),
                'percentage' => (float) ($row['percentage'] ?? 0),
            ])
            ->unique('key')
            ->values()
            ->all();
    }

    private function currencyFor(DonationFormula $formula): string
    {
        return (string) (Donation::query()
            ->where('donation_formula_id', $formula->id)
            ->value('currency') ?: 'usd');
    }
}
