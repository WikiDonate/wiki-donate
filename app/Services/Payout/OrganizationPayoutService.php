<?php

namespace App\Services\Payout;

use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes live organization balances from the current donation formula JSON
 * and the append-only payout ledger (Option A: no donation-time snapshots).
 */
class OrganizationPayoutService
{
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

        $allocations = collect();

        foreach ($formulas as $formula) {
            foreach ($this->normalizeItems($formula->formula) as $item) {
                $owed = $this->owedFor($formula, $item['key']);

                if ($owed->owed <= 0 && $owed->paid <= 0) {
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
                    'currency' => $owed->currency,
                    'owed' => $owed->owed,
                    'paid' => $owed->paid,
                    'balance' => $owed->balance,
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
        return DB::transaction(function () use ($data, $actorId) {
            $formula = $formula = DonationFormula::whereKey($data['donation_formula_id'])->lockForUpdate()->first();
            if (! $formula) {
                throw new Exception('Donation formula not found.');
            }

            $orgName = trim($data['organization_name']);
            $key = OrganizationPayout::makeKey($orgName);

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

            return OrganizationPayout::create([
                'donation_formula_id' => $formula->id,
                'organization_name' => $orgName,
                'organization_key' => $key,
                'amount' => $amount,
                'currency' => $data['currency'],
                'type' => $amount >= $balance->balance - 0.009 ? 'full' : 'partial',
                'status' => 'paid',
                'paid_at' => now(),
                'actor_id' => $actorId,
                'note' => $data['note'] ?? null,
            ]);
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
