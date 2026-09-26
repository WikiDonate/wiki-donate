<?php

namespace App\Services\Payout;

use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\TransactionLog;
use App\Services\PayPalClient;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        $formulaIds = $formulas->pluck('id');

        // Batch aggregate donations per formula
        $donationSums = DB::table('donations')
            ->whereIn('donation_formula_id', $formulaIds)
            ->where('status', 'completed')
            ->groupBy('donation_formula_id')
            ->selectRaw('donation_formula_id, SUM(amount) as total')
            ->pluck('total', 'donation_formula_id');

        // Batch aggregate payouts per (formula_id, organization_key).
        // Paid + pending reserve the balance; failed rows never moved money
        // so they are excluded (admin can retry).
        $payoutSums = DB::table('organization_payouts')
            ->whereIn('donation_formula_id', $formulaIds)
            ->whereIn('status', ['paid', 'pending'])
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
        $orgName = trim($data['organization_name']);
        $key = OrganizationPayout::makeKey($orgName);

        // Resolve the formula's row outside the transaction (read-only) so we
        // can run the destination guard — and persist its audit log — even
        // when the guard rejects the payout.
        $formulaProbe = DonationFormula::whereKey($data['donation_formula_id'])->first();
        $probeMatch = $formulaProbe
            ? collect($this->normalizeItems($formulaProbe->formula))->first(fn ($i) => $i['key'] === $key)
            : null;

        // Payout guard: destination must be a verified org with a PayPal
        // receiving email. Blocks the payout and logs the attempt.
        if ($probeMatch) {
            app(OrganizationPayoutDestinationService::class)
                ->guardPayoutDestination($probeMatch['organization_id'] ?? null, $orgName, $actorId);
        }

        $payout = DB::transaction(function () use ($data, $actorId) {
            $formula = DonationFormula::whereKey($data['donation_formula_id'])->lockForUpdate()->first();
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

            // Re-check inside the lock — belt and braces against an org being
            // unverified between the guard and the write.
            $destination = app(OrganizationPayoutDestinationService::class)
                ->guardPayoutDestination($match['organization_id'] ?? null, $orgName, $actorId);

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

            $type = $amount >= $balance->balance - 0.009 ? 'full' : 'partial';

            // Reserve the allocation in the ledger FIRST, inside the lock.
            // While the payout is pending its amount counts as already paid
            // towards the balance, so a duplicate Pay cannot overdraw.
            $payout = OrganizationPayout::create([
                'donation_formula_id' => $formula->id,
                'organization_id' => $destination->id,
                'organization_name' => $orgName,
                'organization_key' => $key,
                'amount' => $amount,
                'currency' => $data['currency'],
                'type' => $type,
                'status' => 'pending',
                'actor_id' => $actorId,
                'method' => 'paypal',
                'destination_paypal_email' => $destination->paypal_email,
                'note' => $data['note'] ?? null,
            ]);

            return [$payout, $amount, $type, $key, $formula];
        });

        /** @var OrganizationPayout $payout */
        [$payout, $amount, $type, $key, $formula] = $payout;

        // Phase 2 — outside the DB lock: submit the real PayPal transfer.
        // A provider failure marks the row failed (off the balance) instead
        // of rolling back and losing the audit trail.
        $this->submitToPayPal($payout, $amount, $type);

        $this->recordLog($payout, $amount, $type, $actorId);

        return $payout->refresh();
    }

    /**
     * Push a ledger row to the real PayPal Payouts API.
     *
     * Sets payout_batch_id / payout_item_id / provider_status and flips the
     * row: pending → paid (funds moved) or pending → failed (rejected with
     * reason). Throws a safe message on provider rejection so the dashboard
     * surfaces it; transient network errors leave the row pending for a
     * later sync call.
     */
    private function submitToPayPal(OrganizationPayout $payout, float $amount, string $type): void
    {
        try {
            $batch = app(PayPalClient::class)->createBatchPayout(
                [[
                    'recipient_email' => $payout->destination_paypal_email,
                    'amount' => $amount,
                    'currency' => $payout->currency,
                    'note' => sprintf('WikiDonate payout (%s)', $type),
                    'sender_item_id' => $payout->uuid,
                ]],
                (string) $payout->uuid,
                'WikiDonate payout',
                'Your payout from WikiDonate is on the way.',
            );

            $item = (array) ($batch['items'][0] ?? []);
            $header = (array) ($batch['batch_header'] ?? []);

            // PayPal accepts the batch; the item now has a transaction status
            // (SUCCESS / PENDING / FAILED).
            $providerStatus = strtoupper((string) ($item['transaction_status'] ?? 'PENDING'));
            $itemStatus = strtoupper((string) ($item['payout_item_status'] ?? ''));
            $effective = $providerStatus ?: $itemStatus;

            if ($effective === 'SUCCESS' || $effective === 'COMPLETED') {
                $payout->forceFill([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payout_batch_id' => $header['payout_batch_id'] ?? null,
                    'payout_item_id' => $item['payout_item_id'] ?? null,
                    'provider_status' => $effective,
                    'failure_reason' => null,
                ])->save();

                return;
            }

            if ($effective === 'FAILED' || in_array($itemStatus, ['FAILED', 'RETURNED', 'BLOCKED'], true)) {
                $reason = (string) ($item['errors']['message'] ?? ($item['errors']['description'] ?? 'PayPal rejected the payout item'));
                $payout->forceFill([
                    'status' => 'failed',
                    'payout_batch_id' => $header['payout_batch_id'] ?? null,
                    'payout_item_id' => $item['payout_item_id'] ?? null,
                    'provider_status' => $effective ?: $itemStatus,
                    'failure_reason' => mb_substr($reason, 0, 1000),
                ])->save();

                throw new Exception('PayPal rejected the payout: '.$reason);
            }

            // PENDING (normal: PayPal may take minutes to clear) — store the
            // tracking ids and stay pending until syncPending() confirms.
            $payout->forceFill([
                'status' => 'pending',
                'paid_at' => null,
                'payout_batch_id' => $header['payout_batch_id'] ?? null,
                'payout_item_id' => $item['payout_item_id'] ?? null,
                'provider_status' => $effective ?: 'PENDING',
                'failure_reason' => null,
            ])->save();
        } catch (Exception $e) {
            // Provider rejection already recorded the row as failed above
            // (and re-throws). A network/5xx error leaves it pending.
            if ($payout->status === 'failed') {
                throw $e;
            }

            Log::error('PayPal payout submission failed; row left pending', [
                'payout_uuid' => $payout->uuid,
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reconcile pending ledger rows against the PayPal Payouts API.
     *
     * Pending → paid once PayPal reports item SUCCESS; pending → failed on
     * a definitive rejection; stays pending otherwise. Returns the payouts
     * whose status changed.
     */
    public function syncPending(): Collection
    {
        $pending = OrganizationPayout::query()
            ->where('status', 'pending')
            ->whereNotNull('payout_batch_id')
            ->get();

        $changed = collect();

        foreach ($pending as $payout) {
            try {
                $batch = app(PayPalClient::class)->showBatchPayout($payout->payout_batch_id);
                $statuses = PayPalClient::extractBatchItemStatuses($batch);
                $info = $statuses[$payout->uuid] ?? null;

                if (! $info) {
                    continue;
                }

                $effective = strtoupper($info['status']);

                if ($effective === 'SUCCESS' || $effective === 'COMPLETED') {
                    $payout->forceFill([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'provider_status' => $effective,
                        'failure_reason' => null,
                    ])->save();
                    $changed->push($payout);

                    TransactionLog::record('payout.synced', [
                        'actor_id' => null,
                        'actor_role' => 'System',
                        'subject_type' => OrganizationPayout::class,
                        'subject_id' => $payout->id,
                        'message' => sprintf(
                            'PayPal confirmed payout of %.2f %s to %s',
                            $payout->amount,
                            strtoupper($payout->currency),
                            $payout->organization_name
                        ),
                        'after' => ['status' => 'paid', 'paid_at' => $payout->paid_at?->toDateTimeString()],
                    ]);
                } elseif (in_array($effective, ['FAILED', 'RETURNED', 'BLOCKED'], true)) {
                    $payout->forceFill([
                        'status' => 'failed',
                        'provider_status' => $effective,
                        'failure_reason' => mb_substr((string) ($info['error'] ?? 'PayPal reported a failed payout item'), 0, 1000),
                    ])->save();
                    $changed->push($payout);

                    TransactionLog::record('payout.failed', [
                        'actor_id' => null,
                        'actor_role' => 'System',
                        'subject_type' => OrganizationPayout::class,
                        'subject_id' => $payout->id,
                        'message' => sprintf(
                            'PayPal rejected payout of %.2f %s to %s — %s',
                            $payout->amount,
                            strtoupper($payout->currency),
                            $payout->organization_name,
                            $payout->failure_reason
                        ),
                        'after' => ['status' => 'failed', 'provider_status' => $effective],
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('PayPal payout sync failed for row '.$payout->id, ['error' => $e->getMessage()]);
            }
        }

        return $changed;
    }

    private function recordLog(OrganizationPayout $payout, float $amount, string $type, int $actorId): void
    {
        TransactionLog::record('payout.created', [
            'subject_type' => OrganizationPayout::class,
            'subject_id' => $payout->id,
            'actor_id' => $actorId,
            'actor_role' => 'Admin',
            'message' => sprintf(
                '%s payout of %.2f %s to %s (%s) via PayPal',
                $type,
                $amount,
                strtoupper($payout->currency),
                $payout->organization_name,
                $payout->destination_paypal_email
            ),
            'after' => [
                'organization_id' => $payout->organization_id,
                'organization_name' => $payout->organization_name,
                'amount' => $amount,
                'currency' => strtoupper($payout->currency),
                'type' => $type,
                'status' => $payout->status,
                'destination_paypal_email' => $payout->destination_paypal_email,
                'payout_batch_id' => $payout->payout_batch_id,
                'payout_item_id' => $payout->payout_item_id,
            ],
        ]);
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
            ->whereIn('status', ['paid', 'pending'])
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
                'organization_id' => isset($row['organization_id']) ? (int) $row['organization_id'] : null,
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
