<?php

namespace App\Services\Payout;

use App\Exceptions\UserFacingException;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\PaymentMethod;
use App\Models\TransactionLog;
use App\Rules\SupportedCurrency;
use App\Services\Payment\PaymentProviderRegistry;
use App\Services\PayPalClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * How long a payout row may sit `pending` without a provider batch id
     * before a sync re-submits it.
     *
     * A connection-level failure (DNS, TLS, timeout) leaves the row pending
     * with no batch id. The outcome is ambiguous: the request may or may not
     * have reached PayPal, so the row must NEVER be released. After this grace
     * window sync re-submits it with the same `sender_batch_id` (the row
     * uuid), which PayPal dedupes — reconciling instead of double-paying.
     */
    public const STUCK_PENDING_MINUTES = 30;

    /**
     * How far back a `--recheck-paid` sync re-polls already-paid rows for a
     * late reversal (RETURNED / REFUNDED / REVERSED / UNCLAIMED).
     *
     * PayPal can claw funds back well after SUCCESS, and the webhook is only
     * a latency optimisation — polling is the guaranteed path.
     */
    public const REVERSAL_RECHECK_DAYS = 45;

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

        // The platform is USD-only (config/donations.php), so donations are
        // summed into a single total per formula. Legacy rows in another
        // currency are EXCLUDED: counting EUR/GBP amounts as USD would inflate
        // the balance and overpay. `donations:legacy-currency` reports them.
        $donationSums = DB::table('donations')
            ->whereIn('donation_formula_id', $formulaIds)
            ->where('status', 'completed')
            ->whereRaw('UPPER(currency) = ?', [SupportedCurrency::code()])
            ->groupBy('donation_formula_id')
            ->selectRaw('donation_formula_id, SUM(amount) as total')
            ->pluck('total', 'donation_formula_id');

        // Batch aggregate payouts per (formula_id, organization_key).
        // Paid + pending reserve the balance; failed rows never moved money
        // so they are excluded (admin can retry), and reversed rows came back.
        $payoutSums = $this->payoutSumsByKey($formulaIds);

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
            $totalDonated = round((float) ($donationSums->get($formula->id) ?? 0), 2);
            $formulaPayouts = collect($payoutSums->get($formula->id, collect()));

            foreach ($this->normalizeItems($formula->formula) as $item) {
                $org = ($item['organization_id'] ? $orgsById->get($item['organization_id']) : null)
                    ?? $orgsByKey->get($item['key']);

                $owed = round($totalDonated * ($item['percentage'] / 100), 2);
                $paid = $this->paidForPayoutSet($formulaPayouts, $item['key'], $org?->id);

                if ($owed <= 0 && $paid <= 0) {
                    continue;
                }

                $allocations->push($this->allocationPayload(
                    $formula, $item, $org, $owed, $paid
                ));
            }
        }

        return $allocations;
    }

    /**
     * @param  array{name:string,key:string,organization_id:?int,percentage:float}  $item
     */
    private function allocationPayload(
        DonationFormula $formula,
        array $item,
        ?Organization $org,
        float $owed,
        float $paid,
    ): array {
        return [
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
            'currency' => SupportedCurrency::code(),
            'owed' => $owed,
            'paid' => $paid,
            'balance' => round($owed - $paid, 2),
            // Forced rate the Pay modal must mirror as read-only
            // "transaction cost" / "net to organization" rows.
            'transaction_cost_percent' => $this->transactionCostPercent(),
        ];
    }

    /**
     * Reserved payout totals per formula, grouped in PHP so each row keeps
     * BOTH its organization_key and organization_id (needed for the
     * rename-safe paid lookup).
     *
     * @param  Collection<int, int>  $formulaIds
     * @return Collection<int, Collection<int, array{key:string, id:?int, total:float}>>
     */
    private function payoutSumsByKey(Collection $formulaIds): Collection
    {
        return DB::table('organization_payouts')
            ->whereIn('donation_formula_id', $formulaIds)
            ->whereIn('status', OrganizationPayout::ON_BALANCE_STATUSES)
            ->whereNull('reversed_at')
            ->selectRaw('donation_formula_id, organization_key, organization_id, SUM(amount) as total')
            ->groupBy('donation_formula_id', 'organization_key', 'organization_id')
            ->get()
            ->groupBy('donation_formula_id')
            ->map(fn ($rows) => collect($rows)->map(fn ($r) => [
                'key' => (string) $r->organization_key,
                'id' => $r->organization_id === null ? null : (int) $r->organization_id,
                'total' => (float) $r->total,
            ]));
    }

    /**
     * Paid total for one allocation from a pre-grouped payout set.
     *
     * The ledger stores both the organization_key (normalized name at payout
     * time) and the registry organization_id. When we know the registry row we
     * ALSO match by id, so renaming an organization inside a formula cannot
     * make its history disappear (which would re-open the full balance and
     * allow a double payout).
     */
    private function paidForPayoutSet(Collection $payouts, string $key, ?int $organizationId): float
    {
        // Match by registry id first (rename-proof), then by name key. The
        // union avoids double-counting a row that matches both.
        $total = $payouts
            ->filter(fn ($row) => ($organizationId !== null && $row['id'] === $organizationId)
                || $row['key'] === $key)
            ->sum('total');

        return round((float) $total, 2);
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
     * @throws UserFacingException when validation / overpay fails.
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
        // Normalize once at the boundary. The platform is USD-only, so a code
        // that isn't the supported one is refused here too (defence in depth —
        // the controller already validates it).
        $data['currency'] = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($data['currency'] !== SupportedCurrency::code()) {
            throw new UserFacingException('Only '.SupportedCurrency::code().' payouts are supported.');
        }
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

            $balance = $this->owedFor($formula, $key, $match['organization_id'] ?? null);
            if ($balance->currency !== $data['currency']) {
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
                'submitted_at' => now(),
                'attempt_count' => 0,
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
            // Mark the attempt time before the call so a connection that never
            // returns still leaves a reconcilable timestamp behind.
            $payout->forceFill(['submitted_at' => now()])->save();
            $payout->increment('attempt_count');

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
                    'provider_status' => 'SUCCESS',
                    'failure_reason' => null,
                ])->save();

                return;
            }

            if (PayPalPayoutEvent::isFailure($effective) || PayPalPayoutEvent::isFailure($itemStatus)) {
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

            // A brand-new batch that already reports a reversal (rare, but
            // PayPal can return an item immediately). Release the balance.
            if (PayPalPayoutEvent::isReversal($effective) || PayPalPayoutEvent::isReversal($itemStatus)) {
                $payout->forceFill([
                    'payout_batch_id' => $header['payout_batch_id'] ?? null,
                    'payout_item_id' => $item['payout_item_id'] ?? null,
                ])->save();
                $this->markPayoutReversed(
                    $payout,
                    $effective ?: $itemStatus,
                    (string) ($item['errors']['message'] ?? '')
                );

                return;
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
        } catch (Throwable $e) {
            // Provider rejection already recorded the row as failed above
            // (and re-throws). A network/5xx error leaves it pending.
            // Throwable, not Exception: a TypeError/Error escaping the HTTP
            // layer must not skip the ledger bookkeeping below.
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

            // The batch call itself was rejected. Some rejections are NOT
            // definitive and must never release the balance:
            //
            //   - Duplicate sender_batch_id → PayPal already holds this batch.
            //     Reconciling to it is the only safe move; retrying with a new
            //     id (what a `failed` row would invite) pays twice.
            //   - 5xx → PayPal documents that a retry with the SAME
            //     sender_batch_id is safe, so keep the row pending and let the
            //     scheduler re-submit it.
            //
            // Everything else (401/403/422) moved no money and is failed.
            $response = $e instanceof RequestException ? $e->response : null;

            if ($response) {
                if ($batchId = $this->duplicateBatchId($response)) {
                    $payout->forceFill([
                        'status' => 'pending',
                        'payout_batch_id' => $batchId,
                        'provider_status' => 'PENDING',
                        'failure_reason' => null,
                    ])->save();

                    Log::warning('Payout reconciled from a duplicate sender_batch_id', [
                        'payout_uuid' => $payout->uuid,
                        'payout_id' => $payout->id,
                        'payout_batch_id' => $batchId,
                    ]);

                    return;
                }

                if ($response->status() >= 500) {
                    Log::warning('Payout submission got a provider 5xx; retrying later with the same sender_batch_id', [
                        'payout_uuid' => $payout->uuid,
                        'payout_id' => $payout->id,
                        'status' => $response->status(),
                    ]);

                    return;
                }

                $reason = $response->json('message')
                    ?: ($response->json('error_description') ?? $e->getMessage());
                $payout->forceFill([
                    'status' => 'failed',
                    'failure_reason' => mb_substr((string) $reason, 0, 1000),
                ])->save();

                throw new UserFacingException('PayPal rejected the payout: '.$reason);
            }

            Log::error('PayPal payout submission failed; row left pending for recovery', [
                'payout_uuid' => $payout->uuid,
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pull the original payout batch id out of a duplicate-sender_batch_id
     * error.
     *
     * PayPal refuses a `sender_batch_id` reused within 30 days and returns a
     * HATEOAS link to the batch it already created. Following that link is
     * what lets a retry reconcile instead of paying a second time.
     */
    private function duplicateBatchId(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        foreach ((array) ($body['links'] ?? []) as $link) {
            $href = (string) ($link['href'] ?? '');

            // ~ delimiter, not #: the query/fragment character class below
            // contains a literal '#' which would terminate a #-delimited regex.
            if (preg_match('~/v1/payments/payouts/([A-Za-z0-9]{6,30})(?:[/?#]|$)~', $href, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Reconcile ledger rows against the provider.
     *
     * Three passes:
     *   1. Recovery — rows pending past the grace window with NO batch id are
     *      re-submitted with the SAME sender_batch_id. PayPal dedupes that id,
     *      so a request that actually reached PayPal reconciles to the original
     *      batch instead of being released (releasing would double-pay on the
     *      admin's retry). Ambiguous outcomes stay pending rather than failed.
     *   2. Status   — pending rows with a batch id are polled; → paid on
     *      SUCCESS, → failed on a definitive rejection, → reversed on a
     *      post-processing return/refund.
     *   3. Paid     — optional (scheduled daily): recently paid rows are
     *      re-polled so a late RETURNED/REFUNDED is caught even without a
     *      webhook delivery.
     *
     * @return array{checked:int, changed:Collection<int, OrganizationPayout>, failed:int}
     */
    public function syncPending(bool $recheckPaid = false): array
    {
        $changed = collect();
        $failed = 0;

        // ── Pass 1: recover rows that never got a provider batch id. ──────
        $stuckCutoff = now()->subMinutes(self::STUCK_PENDING_MINUTES);
        $stuck = OrganizationPayout::query()
            ->where('status', 'pending')
            ->whereNull('payout_batch_id')
            ->where(fn ($q) => $q->where('submitted_at', '<=', $stuckCutoff)
                ->orWhere(fn ($q2) => $q2->whereNull('submitted_at')
                    ->where('created_at', '<=', $stuckCutoff)))
            ->get();

        foreach ($stuck as $payout) {
            $statusBefore = $payout->status;
            $batchBefore = $payout->payout_batch_id;

            try {
                // Re-submitting with the row's uuid (= sender_batch_id) is
                // idempotent at PayPal: if it never processed, this creates the
                // batch; if it did, PayPal points back at the original batch.
                $this->submitToProvider(
                    $payout,
                    (float) ($payout->net_amount ?? $payout->amount),
                    $payout->type ?: 'partial',
                );
            } catch (Throwable $e) {
                // A definitive 4xx marks the row failed inside submitToProvider;
                // anything else stays pending for the next run.
                Log::warning('Payout recovery attempt failed for row '.$payout->id, [
                    'error' => $e->getMessage(),
                ]);
            }

            $payout->refresh();

            if ($payout->status !== $statusBefore || $payout->payout_batch_id !== $batchBefore) {
                $changed->push($payout);
            }
        }

        // ── Pass 2: poll pending rows that have a batch id. ───────────────
        $pending = OrganizationPayout::query()
            ->where('status', 'pending')
            ->whereNotNull('payout_batch_id')
            ->get();

        foreach ($pending as $payout) {
            if (! $this->canSyncStatus($payout)) {
                continue;
            }

            try {
                $statuses = PayPalClient::extractBatchItemStatuses(
                    app(PayPalClient::class)->showBatchPayout($payout->payout_batch_id)
                );
                $info = $statuses[$payout->uuid] ?? null;

                if (! $info) {
                    continue;
                }

                $effective = strtoupper($info['status']);

                // Reversals first: RETURNED/UNCLAIMED/REFUNDED/REVERSED are
                // real item statuses that used to be ignored or mislabelled as
                // `failed`. Re-open the balance instead of losing the funds.
                if (PayPalPayoutEvent::isReversal($effective)) {
                    if ($this->markPayoutReversed($payout, $effective, (string) ($info['error'] ?? ''))) {
                        $changed->push($payout);
                    }

                    continue;
                }

                if ($effective === 'SUCCESS' || $effective === 'COMPLETED') {
                    if ($this->applyStatus($payout, ['pending'], [
                        'status' => 'paid',
                        'paid_at' => now(),
                        'provider_status' => $effective,
                        'failure_reason' => null,
                    ])) {
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
                    }

                    continue;
                }

                if (in_array($effective, PayPalPayoutEvent::FAILURE_STATUSES, true)) {
                    if ($this->applyStatus($payout, ['pending'], [
                        'status' => 'failed',
                        'provider_status' => $effective,
                        'failure_reason' => mb_substr((string) ($info['error'] ?? 'PayPal reported a failed payout item'), 0, 1000),
                    ])) {
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
                }
            } catch (Throwable $e) {
                Log::warning('PayPal payout sync failed for row '.$payout->id, ['error' => $e->getMessage()]);
                $failed++;
            }
        }

        // ── Pass 3: re-poll recently paid rows for late reversals. ────────
        $paidChecked = 0;

        if ($recheckPaid) {
            $recentPaid = OrganizationPayout::query()
                ->where('status', 'paid')
                ->whereNull('reversed_at')
                ->whereNotNull('payout_batch_id')
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', now()->subDays(self::REVERSAL_RECHECK_DAYS))
                ->get();

            $paidChecked = $recentPaid->count();

            foreach ($recentPaid as $payout) {
                if (! $this->canSyncStatus($payout)) {
                    continue;
                }

                try {
                    $statuses = PayPalClient::extractBatchItemStatuses(
                        app(PayPalClient::class)->showBatchPayout($payout->payout_batch_id)
                    );
                    $info = $statuses[$payout->uuid] ?? null;

                    if ($info && PayPalPayoutEvent::isReversal(strtoupper($info['status']))) {
                        if ($this->markPayoutReversed(
                            $payout,
                            strtoupper($info['status']),
                            (string) ($info['error'] ?? '')
                        )) {
                            $changed->push($payout);
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('Paid payout recheck failed for row '.$payout->id, ['error' => $e->getMessage()]);
                    $failed++;
                }
            }
        }

        return [
            'checked' => $stuck->count() + $pending->count() + $paidChecked,
            'changed' => $changed,
            'failed' => $failed,
        ];
    }

    /**
     * Whether the row's rail can report a provider-side status.
     *
     * Only PayPal is bound as a payout-status rail today. A row from any
     * other rail keeps its status untouched rather than being guessed at.
     */
    private function canSyncStatus(OrganizationPayout $payout): bool
    {
        $method = strtolower((string) ($payout->payment_method ?: PaymentMethod::CODE_PAYPAL));

        if ($method === PaymentMethod::CODE_PAYPAL) {
            return true;
        }

        Log::warning('Payout sync skipped: method has no status rail.', [
            'payout_id' => $payout->id,
            'payment_method' => $payout->payment_method,
        ]);

        return false;
    }

    /**
     * Conditional status transition: only applies when the row is still in one
     * of `$from`.
     *
     * This closes the sync/webhook race — a webhook reversal that lands while
     * sync is polling must not be overwritten by a stale `SUCCESS`. The DB does
     * the compare-and-set atomically, and the caller only records a change when
     * the update actually hit a row.
     *
     * @param  array<int, string>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function applyStatus(OrganizationPayout $payout, array $from, array $attributes): bool
    {
        $affected = OrganizationPayout::query()
            ->whereKey($payout->getKey())
            ->whereIn('status', $from)
            ->update($attributes);

        if ($affected === 0) {
            return false;
        }

        $payout->refresh();

        return true;
    }

    /**
     * Record a post-processing reversal without editing the original amounts.
     *
     * The row keeps its gross/fee/net; `reversed_at` re-opens the balance and
     * the status becomes `reversed` so the ledger stays append-only and the
     * history explains exactly what happened.
     *
     * Idempotent: an already-reversed row is left alone, and the guarded update
     * prevents a concurrent sync/webhook pair from recording the reversal (and
     * its audit entry) twice.
     *
     * @return bool Whether this call performed the reversal.
     */
    private function markPayoutReversed(OrganizationPayout $payout, string $providerStatus, string $reason = ''): bool
    {
        $affected = OrganizationPayout::query()
            ->whereKey($payout->getKey())
            ->whereNull('reversed_at')
            ->whereIn('status', ['pending', 'paid'])
            ->update([
                'status' => 'reversed',
                'provider_status' => $providerStatus,
                'reversed_at' => now(),
                'failure_reason' => mb_substr(
                    $reason !== '' ? $reason : "PayPal reported a {$providerStatus} transfer; funds returned.",
                    0,
                    1000
                ),
            ]);

        if ($affected === 0) {
            return false;
        }

        $payout->refresh();

        TransactionLog::record('payout.reversed', [
            'actor_id' => null,
            'actor_role' => 'System',
            'subject_type' => OrganizationPayout::class,
            'subject_id' => $payout->id,
            'message' => sprintf(
                'Payout of %.2f %s to %s was reversed by PayPal (%s); the balance is available again.',
                $payout->amount,
                strtoupper($payout->currency),
                $payout->organization_name,
                $providerStatus
            ),
            'after' => [
                'status' => 'reversed',
                'provider_status' => $providerStatus,
                'reversed_at' => $payout->reversed_at?->toDateTimeString(),
            ],
            'meta' => [
                'payment_method' => $payout->payment_method ?: 'paypal',
                'gross_amount' => (float) $payout->amount,
            ],
        ]);

        return true;
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
     * Resolve the registry id an allocation belongs to, tolerating renames.
     *
     * The formula row wins (it carries the id the editor chose); if the row has
     * no id, or the name no longer matches a formula row, fall back to the
     * registry's normalized name. Returns null for a name that is not known at
     * all, in which case callers match on the stored key only.
     */
    public function resolveOrganizationId(DonationFormula $formula, string $orgName): ?int
    {
        $key = OrganizationPayout::makeKey($orgName);
        $item = collect($this->normalizeItems($formula->formula))
            ->first(fn ($i) => $i['key'] === $key);

        if ($item && ! empty($item['organization_id'])) {
            return (int) $item['organization_id'];
        }

        $id = Organization::query()
            ->where('normalized_name', Organization::normalizeName($orgName))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Payout history rows for an allocation (newest first).
     *
     * Rename-safe: the org is resolved from the current formula/registry so the
     * lookup matches BOTH the stored `organization_key` and the
     * `organization_id`, mirroring the balance math. Matching on the key alone
     * made the history panel go empty after an org rename even though the
     * balance still showed the paid amounts.
     */
    public function history(DonationFormula $formula, string $orgName): Collection
    {
        $key = OrganizationPayout::makeKey($orgName);
        $organizationId = $this->resolveOrganizationId($formula, $orgName);

        return OrganizationPayout::query()
            ->where('donation_formula_id', $formula->id)
            ->where(function ($q) use ($key, $organizationId) {
                $q->where('organization_key', $key);

                if ($organizationId !== null) {
                    $q->orWhere('organization_id', $organizationId);
                }
            })
            ->with('actor:id,uuid,username')
            ->latest('created_at')
            ->get();
    }

    private function owedFor(
        DonationFormula $formula,
        string $key,
        ?int $organizationId = null,
    ): object {
        $items = $this->normalizeItems($formula->formula);
        $item = collect($items)->first(fn ($i) => $i['key'] === $key);

        // Reserved payouts: exclude failed rows and anything reversed. When we
        // know the registry row we also match by organization_id so a rename
        // cannot detach the history (see paidForPayoutSet()).
        $payoutQuery = OrganizationPayout::query()
            ->where('donation_formula_id', $formula->id)
            ->whereIn('status', OrganizationPayout::ON_BALANCE_STATUSES)
            ->whereNull('reversed_at');

        $paidQuery = clone $payoutQuery;
        if ($organizationId !== null) {
            $paidQuery->where(function ($q) use ($organizationId, $key) {
                $q->where('organization_id', $organizationId)
                    ->orWhere('organization_key', $key);
            });
        } else {
            $paidQuery->where('organization_key', $key);
        }

        $paid = round((float) $paidQuery->sum('amount'), 2);

        if (! $item) {
            // Organization no longer exists in the current formula: still
            // show what was paid, but nothing more is owed.
            return (object) [
                'owed' => $paid,
                'paid' => $paid,
                'balance' => 0.0,
                'currency' => SupportedCurrency::code(),
            ];
        }

        $total = (float) Donation::query()
            ->where('donation_formula_id', $formula->id)
            ->where('status', 'completed')
            ->whereRaw('UPPER(currency) = ?', [SupportedCurrency::code()])
            ->sum('amount');

        $owed = round($total * ($item['percentage'] / 100), 2);

        return (object) [
            'owed' => $owed,
            'paid' => $paid,
            'balance' => round($owed - $paid, 2),
            'currency' => SupportedCurrency::code(),
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
}
