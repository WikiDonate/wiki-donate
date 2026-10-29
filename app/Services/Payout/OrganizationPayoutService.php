<?php

namespace App\Services\Payout;

use App\Exceptions\UserFacingException;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\PaymentMethod;
use App\Models\TransactionLog;
use App\Services\Payment\PaymentProviderRegistry;
use App\Services\PayPalClient;
use Exception;
use Illuminate\Http\Client\RequestException;
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
     * PayPal Payouts rejects transfers below 1.00 USD-equivalent in most
     * corridors; keeping our own floor at the same value means we never
     * submit a batch item the provider would bounce.
     */
    public const MIN_NET_SEND_AMOUNT = 1.00;

    /**
     * Forced transaction-cost rate (percent) from config/donations.php.
     *
     * The admin always types the GROSS amount; this rate is deducted
     * server-side. Never trust a client-supplied fee/net value.
     */
    public function transactionCostPercent(): float
    {
        return (float) config('donations.transaction_cost_percent', 5);
    }

    /**
     * Split a gross amount into its enforced fee / net parts.
     *
     * Rounding is round-half-up to cents on the FEE, and net is derived as
     * gross − fee so the pair always sums back to the gross amount exactly
     * (no lost cent at boundaries such as 0.10 → 0.01 fee / 0.09 net).
     *
     * @return array{percent: float, fee: float, net: float}
     */
    public function splitTransactionCost(float $gross): array
    {
        $percent = $this->transactionCostPercent();
        $gross = round($gross, 2);
        $fee = round($gross * ($percent / 100), 2, PHP_ROUND_HALF_UP);
        // Guard against float drift making fee > gross on tiny sends.
        $fee = min($fee, $gross);

        return [
            'percent' => round($percent, 2),
            'fee' => $fee,
            'net' => round($gross - $fee, 2),
        ];
    }

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

        // Batch-load registry rows so each allocation knows its verification
        // state (Pay button disabled for unverified). Rows saved before
        // organization_id existed resolve via normalized name.
        $orgIds = $formulas->flatMap(fn ($f) => collect($this->normalizeItems($f->formula))
            ->pluck('organization_id')->filter())->unique()->values();
        $orgsById = $orgIds->isEmpty()
            ? collect()
            : Organization::whereIn('id', $orgIds)->get()->keyBy('id');
        $orgKeys = $formulas->flatMap(fn ($f) => collect($this->normalizeItems($f->formula))
            ->map(fn ($i) => $i['key']))->unique()->values();
        $orgsByKey = $orgKeys->isEmpty()
            ? collect()
            : Organization::whereIn('normalized_name', $orgKeys)->get()->keyBy('normalized_name');

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

                $org = ($item['organization_id'] ? $orgsById->get($item['organization_id']) : null)
                    ?? $orgsByKey->get($item['key']);

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
                    'organization_id' => $org?->id ?? $item['organization_id'],
                    'payout_status' => $org?->payout_status ?? 'unverified',
                    'is_verified' => $org ? $org->isVerified() : false,
                    'percentage' => $item['percentage'],
                    'currency' => $currency,
                    'owed' => $owed,
                    'paid' => $paid,
                    'balance' => $balance,
                    // Forced rate the Pay modal must mirror as read-only
                    // "transaction cost" / "net to organization" rows.
                    'transaction_cost_percent' => $this->transactionCostPercent(),
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
        // The transaction cost is FORCED. A caller may only submit the gross
        // amount; any attempt to hand in a fee or a net figure is rejected
        // outright rather than silently ignored, so a stale/rogue client can
        // never pay gross while believing it paid net (or vice versa).
        foreach (['net_amount', 'fee_amount', 'fee_percent', 'net', 'fee'] as $forbidden) {
            if (array_key_exists($forbidden, $data) && $data[$forbidden] !== null && $data[$forbidden] !== '') {
                throw new UserFacingException(
                    'The transaction cost is applied automatically; only the gross amount may be submitted.'
                );
            }
        }

        $orgName = trim($data['organization_name']);
        $key = OrganizationPayout::makeKey($orgName);
        // Normalize once at the boundary: donations store mixed case
        // (Stripe `usd`, PayPal `USD`) and callers send either form.
        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? '')));
        // Payout rail is data-driven: the caller (admin UI) sends the chosen
        // payout-capable method code; PayPal stays the default for legacy
        // callers that predate the registry. Resolution happens BEFORE the
        // ledger write so a method with no payout rail can never leave a
        // pending row behind that we are unable to pay.
        $paymentMethod = app(PaymentProviderRegistry::class)
            ->resolvePayoutMethod($data['payment_method'] ?? null);

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
                ->guardPayoutDestination($probeMatch['organization_id'] ?? null, $orgName, $actorId, $paymentMethod);
        }

        $payout = DB::transaction(function () use ($data, $actorId, $paymentMethod) {
            $formula = DonationFormula::whereKey($data['donation_formula_id'])->lockForUpdate()->first();
            if (! $formula) {
                throw new UserFacingException('Donation formula not found.');
            }

            $orgName = trim($data['organization_name']);
            $key = OrganizationPayout::makeKey($orgName);

            $items = $this->normalizeItems($formula->formula);
            $match = collect($items)->first(fn ($i) => $i['key'] === $key);
            if (! $match) {
                throw new UserFacingException('Organization is not allocated in this formula (anymore).');
            }

            // Re-check inside the lock — belt and braces against an org being
            // unverified between the guard and the write.
            $destination = app(OrganizationPayoutDestinationService::class)
                ->guardPayoutDestination($match['organization_id'] ?? null, $orgName, $actorId, $paymentMethod);

            $balance = $this->owedFor($formula, $key);
            if ($balance->currency && $data['currency'] !== strtoupper($balance->currency)) {
                throw new UserFacingException("Currency mismatch: allocation currency is {$balance->currency}.");
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new UserFacingException('Payout amount must be greater than 0.');
            }
            if ($amount > $balance->balance + 0.009) {
                throw new UserFacingException(sprintf(
                    'Payout of %.2f exceeds the live balance of %.2f.',
                    $amount,
                    $balance->balance
                ));
            }

            // Forced transaction-cost deduction: gross in, net out.
            $split = $this->splitTransactionCost($amount);
            if ($split['net'] < self::MIN_NET_SEND_AMOUNT) {
                throw new UserFacingException(sprintf(
                    'Net amount of %.2f is below the minimum transfer of %.2f (after the %.2f%% transaction cost on a gross of %.2f).',
                    $split['net'],
                    self::MIN_NET_SEND_AMOUNT,
                    $split['percent'],
                    $amount
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
                'fee_percent' => $split['percent'],
                'fee_amount' => $split['fee'],
                'net_amount' => $split['net'],
                'currency' => $data['currency'],
                'type' => $type,
                'status' => 'pending',
                'actor_id' => $actorId,
                'payment_method' => $paymentMethod,
                'destination_paypal_email' => $destination->paypal_email,
                'note' => $data['note'] ?? null,
            ]);

            return [$payout, $amount, $type, $key, $formula];
        });

        /** @var OrganizationPayout $payout */
        [$payout, $amount, $type, $key, $formula] = $payout;

        // Phase 2 — outside the DB lock: submit the real provider transfer.
        // A provider failure marks the row failed (off the balance) instead
        // of rolling back and losing the audit trail. The log records the
        // provider-response status in every outcome — including rejections.
        try {
            // The organization receives the NET amount: the enforced fee can
            // never be submitted as part of the provider transfer.
            $this->submitToProvider($payout, (float) $payout->net_amount, $type);
        } finally {
            $this->recordLog($payout->refresh(), $amount, $type, $actorId);
        }

        return $payout->refresh();
    }

    /**
     * Push a ledger row through the payout rail bound to its method code.
     *
     * Sets payout_batch_id / payout_item_id / provider_status and flips the
     * row: pending → paid (funds moved) or pending → failed (rejected with
     * reason). Throws a safe message on provider rejection so the dashboard
     * surfaces it; transient network errors leave the row pending for a
     * later sync call.
     */
    private function submitToProvider(OrganizationPayout $payout, float $amount, string $type): void
    {
        try {
            $batch = app(PaymentProviderRegistry::class)
                ->payoutClient($payout->payment_method ?: 'paypal')
                ->payout(
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
                $reason = (string) ($item['errors']['message'] ?? ($item['errors']['description'] ?? 'Provider rejected the payout item'));
                $payout->forceFill([
                    'status' => 'failed',
                    'payout_batch_id' => $header['payout_batch_id'] ?? null,
                    'payout_item_id' => $item['payout_item_id'] ?? null,
                    'provider_status' => $effective ?: $itemStatus,
                    'failure_reason' => mb_substr($reason, 0, 1000),
                ])->save();

                throw new UserFacingException('PayPal rejected the payout: '.$reason);
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

            // A configuration error (e.g. missing registry credentials) is a
            // definite "no request was sent" failure: never leave the balance
            // reserved on a row sync can't reconcile.
            if ($e instanceof UserFacingException) {
                $payout->forceFill([
                    'status' => 'failed',
                    'failure_reason' => mb_substr($e->getMessage(), 0, 1000),
                ])->save();

                throw $e;
            }

            // The batch call itself was rejected (401 bad credentials, 403
            // Payouts not enabled, 422 validation): PayPal moved no money,
            // so record the row as failed instead of leaving it pending
            // forever (sync only touches rows WITH a batch id). Only pure
            // connection errors (no response at all) stay pending.
            $response = $e instanceof RequestException ? $e->response : null;
            if ($response) {
                $reason = $response->json('message')
                    ?: ($response->json('error_description') ?? $e->getMessage());
                $payout->forceFill([
                    'status' => 'failed',
                    'failure_reason' => mb_substr((string) $reason, 0, 1000),
                ])->save();

                throw new UserFacingException('PayPal rejected the payout: '.$reason);
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
            // Only methods with a bound payout-status rail can be synced.
            // Today that is PayPal alone; a row from any other rail keeps
            // its status untouched rather than being guessed at.
            if (($payout->payment_method ?: 'paypal') !== PaymentMethod::CODE_PAYPAL) {
                Log::warning('Payout sync skipped: method has no status rail.', [
                    'payout_id' => $payout->id,
                    'payment_method' => $payout->payment_method,
                ]);

                continue;
            }

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
                            'PayPal confirmed payout of %.2f %s gross (net %.2f) to %s',
                            $payout->amount,
                            strtoupper($payout->currency),
                            (float) ($payout->net_amount ?? $payout->amount),
                            $payout->organization_name
                        ),
                        'after' => [
                            'status' => 'paid',
                            'paid_at' => $payout->paid_at?->toDateTimeString(),
                            'amount' => (float) $payout->amount,
                            'fee_amount' => $payout->fee_amount === null ? null : (float) $payout->fee_amount,
                            'net_amount' => $payout->net_amount === null ? null : (float) $payout->net_amount,
                        ],
                        'meta' => [
                            'payment_method' => $payout->payment_method ?: 'paypal',
                            'gross_amount' => (float) $payout->amount,
                            'net_amount' => (float) ($payout->net_amount ?? $payout->amount),
                        ],
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
                            'Payout of %.2f %s to %s via %s rejected — %s',
                            $payout->amount,
                            strtoupper($payout->currency),
                            $payout->organization_name,
                            $payout->payment_method ?: 'paypal',
                            $payout->failure_reason
                        ),
                        'after' => ['status' => 'failed', 'provider_status' => $effective],
                        'meta' => ['payment_method' => $payout->payment_method ?: 'paypal'],
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
                '%s payout of %.2f %s gross (fee %.2f @ %s%%, net %.2f) to %s (%s) via %s',
                $type,
                $amount,
                strtoupper($payout->currency),
                (float) $payout->fee_amount,
                rtrim(rtrim(number_format((float) $payout->fee_percent, 2, '.', ''), '0'), '.'),
                (float) $payout->net_amount,
                $payout->organization_name,
                $payout->destination_paypal_email,
                $payout->payment_method ?: 'paypal'
            ),
            'after' => [
                'organization_id' => $payout->organization_id,
                'organization_name' => $payout->organization_name,
                // amount = gross (consumes the owed balance)
                'amount' => $amount,
                'fee_percent' => (float) $payout->fee_percent,
                'fee_amount' => (float) $payout->fee_amount,
                'net_amount' => (float) $payout->net_amount,
                'currency' => strtoupper($payout->currency),
                'type' => $type,
                'status' => $payout->status,
                'destination_paypal_email' => $payout->destination_paypal_email,
                'payout_batch_id' => $payout->payout_batch_id,
                'payout_item_id' => $payout->payout_item_id,
                'provider_status' => $payout->provider_status,
                'failure_reason' => $payout->failure_reason,
            ],
            'meta' => [
                'payment_method' => $payout->payment_method ?: 'paypal',
                // Rate in force at execution time (frozen on the row too).
                'transaction_cost_percent' => (float) $payout->fee_percent,
                'gross_amount' => (float) $amount,
                'fee_amount' => (float) $payout->fee_amount,
                'net_amount' => (float) $payout->net_amount,
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
        // Canonical uppercase: donations store mixed case depending on the
        // gateway (Stripe `usd`, PayPal `USD`).
        return strtoupper((string) (Donation::query()
            ->where('donation_formula_id', $formula->id)
            ->value('currency') ?: 'usd'));
    }
}
