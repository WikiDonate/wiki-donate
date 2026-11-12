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
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes organization balances from each donation's frozen formula snapshot
 * (falling back to the live formula for legacy rows) and the append-only
 * payout ledger.
 */
class OrganizationPayoutService
{
    /**
     * Provider minimum for a single payout transfer. The amount is sent GROSS
     * and the provider fee is borne by the receiver (`fees_payer=RECEIVER`),
     * so the floor applies to the gross amount — not an estimated net.
     */
    public const MIN_PAYOUT_AMOUNT = 1.00;

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
     * Display-only fee estimate for the admin Pay modal.
     *
     * The real fee depends on the receiver's country/currency and is reported
     * by the provider only after processing, so this percentage must never be
     * recorded as the actual fee or used to compute the transfer amount.
     */
    public function feeEstimatePercent(): float
    {
        return (float) config('donations.payout_fee_estimate_percent', 2);
    }

    /**
     * Record the provider-reported fee for a payout row.
     *
     * PayPal returns the actual fee per item (`payout_item_fee`) once the item
     * is processed, or a batch total in `batch_header.fees`. `amount` is the
     * gross the platform sent; the receiver bears the fee, so `net_amount` is
     * what the organization actually receives.
     *
     * @param  array{currency?: string, value?: float|string}|null  $fee
     */
    public function applyProviderFee(OrganizationPayout $payout, ?array $fee): bool
    {
        $value = $fee['value'] ?? null;

        if ($value === null || $value === '') {
            return false;
        }

        $amount = round((float) $payout->amount, 2);
        $feeAmount = round((float) $value, 2);
        // A fee can never exceed the transfer itself (float/rounding guard).
        $feeAmount = max(0.0, min($feeAmount, $amount));

        $payout->forceFill([
            'fee_amount' => $feeAmount,
            'net_amount' => round($amount - $feeAmount, 2),
            'fee_percent' => $amount > 0 ? round($feeAmount / $amount * 100, 2) : null,
            'fee_source' => 'paypal',
        ])->save();

        return true;
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

        // Owed comes from each donation's frozen snapshot, so a later
        // percentage edit cannot re-divide money already collected or paid.
        // Legacy rows without a snapshot fall back to the live formula.
        $basis = $this->allocationBasis($formulaIds);

        // Batch aggregate payouts per (formula_id, organization_key).
        // Paid + pending reserve the balance; failed rows never moved money
        // so they are excluded (admin can retry), and reversed rows came back.
        $payoutSums = $this->payoutSumsByKey($formulaIds);

        $allocations = collect();

        // Batch-load registry rows so each allocation knows its verification
        // state (Pay button disabled for unverified). Rows saved before
        // organization_id existed resolve via normalized name.
        // Snapshot-only allocations (an org removed from the live formula
        // after it was donated to) still need their registry row.
        $allItems = collect($basis)->flatMap(fn ($data) => array_values($data['items']));
        $orgIds = $allItems->pluck('organization_id')->filter()->unique()->values();
        $orgsById = $orgIds->isEmpty()
            ? collect()
            : Organization::whereIn('id', $orgIds)->get()->keyBy('id');
        $orgKeys = $allItems->pluck('key')->unique()->values();
        $orgsByKey = $orgKeys->isEmpty()
            ? collect()
            : Organization::whereIn('normalized_name', $orgKeys)->get()->keyBy('normalized_name');

        foreach ($formulas as $formula) {
            $data = $basis[$formula->id] ?? ['items' => [], 'owed' => []];
            $formulaPayouts = collect($payoutSums->get($formula->id, collect()));

            foreach ($data['items'] as $item) {
                $org = ($item['organization_id'] ? $orgsById->get($item['organization_id']) : null)
                    ?? $orgsByKey->get($item['key']);

                $owed = $data['owed'][$item['key']] ?? 0.0;
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
            // Forced rate shown in the Pay modal as an ESTIMATE only; the
            // recorded fee comes from the provider after processing.
            'fee_estimate_percent' => $this->feeEstimatePercent(),
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
     * Total owed across every allocatable (formula + organization) pair.
     *
     * Single source of truth for the "remaining payable" figure on the admin
     * transaction summary, so it can never drift from the balance the payouts
     * feature actually pays out of. Owed is derived per donation snapshot
     * (live formula only for legacy rows without one):
     *  - only completed, supported-currency (USD) donations count;
     *  - owed = Σ(donated × snapshot percentage/100), rounded per allocation.
     *
     * @param  callable(Builder): void|null  $donationFilter
     *                                                        Optional extra constraint on the donations query (e.g. a date
     *                                                        window) applied on top of the completed + USD rules.
     */
    public function totalOwed(?callable $donationFilter = null): float
    {
        $formulaIds = DonationFormula::query()->pluck('id');

        if ($formulaIds->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($this->allocationBasis($formulaIds, $donationFilter) as $data) {
            $total += array_sum($data['owed']);
        }

        return round($total, 2);
    }

    /**
     * Payout coverage per formula for read-only donor-facing status labels.
     *
     * Uses the SAME rules as the balance math: owed from each donation's
     * frozen snapshot (live formula for legacy rows), paid from on-balance
     * payouts (paid + pending, reversed excluded). Returns one label per
     * formula id:
     *  - `distributed` — payouts cover every owed allocation;
     *  - `partial`     — some money moved but the formula is not fully paid;
     *  - `pending`     — nothing has been paid out yet.
     *
     * @param  array<int, int|string>  $formulaIds
     * @return array<int, string> formula id => distributed|partial|pending
     */
    public function distributionStatuses(array $formulaIds): array
    {
        $formulaIds = array_values(array_unique(array_filter($formulaIds)));

        if ($formulaIds === []) {
            return [];
        }

        $basis = $this->allocationBasis($formulaIds);

        $paidSums = OrganizationPayout::query()
            ->whereIn('donation_formula_id', $formulaIds)
            ->whereIn('status', OrganizationPayout::ON_BALANCE_STATUSES)
            ->whereNull('reversed_at')
            ->groupBy('donation_formula_id')
            ->selectRaw('donation_formula_id, SUM(amount) as total')
            ->pluck('total', 'donation_formula_id');

        $statuses = [];

        foreach ($basis as $formulaId => $data) {
            $owed = round(array_sum($data['owed']), 2);
            $paid = round((float) ($paidSums->get($formulaId) ?? 0), 2);

            $statuses[$formulaId] = match (true) {
                $owed <= 0 => $paid > 0 ? 'distributed' : 'pending',
                $paid + 0.009 >= $owed => 'distributed',
                $paid > 0 => 'partial',
                default => 'pending',
            };
        }

        return $statuses;
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

            // The provider fee is unknown here: it depends on the receiver's
            // country/currency and is reported by PayPal after processing
            // (the batch sets fees_payer=RECEIVER). Record the gross now and
            // reconcile the actual fee on confirmation.
            if ($amount < self::MIN_PAYOUT_AMOUNT) {
                throw new UserFacingException(sprintf(
                    'Payout amount must be at least %.2f.',
                    self::MIN_PAYOUT_AMOUNT
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
                'submitted_at' => now(),
                'attempt_count' => 0,
                'actor_id' => $actorId,
                'payment_method' => $paymentMethod,
                'destination_paypal_email' => $destination->paypal_email,
                'note' => $data['note'] ?? null,
                'fee_source' => 'pending_provider',
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
            // The gross amount is sent; the receiver bears the provider fee
            // (fees_payer=RECEIVER) and the actual fee is stored on return.
            $this->submitToProvider($payout, $amount, $type);
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

            // The provider's actual fee (receiver-paid) may be reported with
            // the create response; sync/webhook fill it in if it is not.
            $this->applyProviderFee($payout, $item['payout_item_fee'] ?? ($header['fees'] ?? null));

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
                // The GROSS amount is sent — the receiver bears the fee.
                $this->submitToProvider(
                    $payout,
                    (float) $payout->amount,
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

        // One pooled round trip for every batch instead of a serial call per
        // row (a PayPal poll is ~7s; ten pending rows used to take a minute).
        $syncablePending = $pending->filter(fn (OrganizationPayout $p) => $this->canSyncStatus($p));
        $batchResults = $syncablePending->isEmpty()
            ? []
            : app(PayPalClient::class)->showBatchPayouts($syncablePending->pluck('payout_batch_id')->all());

        foreach ($syncablePending as $payout) {
            try {
                $batch = $batchResults[$payout->payout_batch_id] ?? null;

                if ($batch === null || $batch['payload'] === null) {
                    Log::warning('PayPal payout sync failed for row '.$payout->id, [
                        'error' => $batch['error'] ?? 'No batch response',
                    ]);
                    $failed++;

                    continue;
                }

                $statuses = PayPalClient::extractBatchItemStatuses($batch['payload']);
                $info = $statuses[$payout->uuid] ?? null;

                if (! $info) {
                    continue;
                }

                // Fee is provider-reported and may arrive with any status.
                $this->applyProviderFee($payout, $info['fee'] ?? null);

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

            $syncablePaid = $recentPaid->filter(fn (OrganizationPayout $p) => $this->canSyncStatus($p));
            $paidBatchResults = $syncablePaid->isEmpty()
                ? []
                : app(PayPalClient::class)->showBatchPayouts($syncablePaid->pluck('payout_batch_id')->all());

            foreach ($syncablePaid as $payout) {
                try {
                    $batch = $paidBatchResults[$payout->payout_batch_id] ?? null;

                    if ($batch === null || $batch['payload'] === null) {
                        Log::warning('Paid payout recheck failed for row '.$payout->id, [
                            'error' => $batch['error'] ?? 'No batch response',
                        ]);
                        $failed++;

                        continue;
                    }

                    $statuses = PayPalClient::extractBatchItemStatuses($batch['payload']);
                    $info = $statuses[$payout->uuid] ?? null;

                    $this->applyProviderFee($payout, $info['fee'] ?? null);

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
                '%s payout of %.2f %s gross to %s (%s) via %s%s',
                $type,
                $amount,
                strtoupper($payout->currency),
                $payout->organization_name,
                $payout->destination_paypal_email,
                $payout->payment_method ?: 'paypal',
                $payout->fee_amount === null
                    ? ' — provider fee pending'
                    : sprintf(
                        ' (provider fee %.2f, net %.2f)',
                        (float) $payout->fee_amount,
                        (float) $payout->net_amount
                    )
            ),
            'after' => [
                'organization_id' => $payout->organization_id,
                'organization_name' => $payout->organization_name,
                // amount = gross (consumes the owed balance)
                'amount' => $amount,
                'fee_percent' => $payout->fee_percent === null ? null : (float) $payout->fee_percent,
                'fee_amount' => $payout->fee_amount === null ? null : (float) $payout->fee_amount,
                'net_amount' => $payout->net_amount === null ? null : (float) $payout->net_amount,
                'fee_source' => $payout->fee_source,
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
                // Fee is provider-reported; pending rows carry no amount yet.
                'fee_source' => $payout->fee_source,
                'gross_amount' => (float) $amount,
                'fee_amount' => $payout->fee_amount === null ? null : (float) $payout->fee_amount,
                'net_amount' => $payout->net_amount === null ? null : (float) $payout->net_amount,
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
        $basis = $this->allocationBasis([$formula->id]);
        $item = $basis[$formula->id]['items'][$key] ?? null;

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

        $owed = $basis[$formula->id]['owed'][$key] ?? 0.0;

        return (object) [
            'owed' => $owed,
            'paid' => $paid,
            'balance' => round($owed - $paid, 2),
            'currency' => SupportedCurrency::code(),
        ];
    }

    /**
     * Owed per (formula, organization key) plus the allocatable items.
     *
     * Owed is accumulated from each donation's frozen `formula_snapshot`, so
     * editing a formula's percentages later cannot re-divide money that was
     * already collected or paid out (which used to produce negative balances
     * and overpayments). Legacy donations without a snapshot fall back to the
     * live formula.
     *
     * Snapshot-only items (an organization removed from the live formula after
     * being donated to) are kept so their share stays payable.
     *
     * @param  Collection<int, int|string>|array<int, int|string>  $formulaIds
     * @param  callable(Builder): void|null  $donationFilter
     * @return array<int, array{items: array<string, array>, owed: array<string, float>}>
     */
    private function allocationBasis($formulaIds, ?callable $donationFilter = null): array
    {
        $ids = collect($formulaIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $basis = [];

        DonationFormula::query()
            ->whereIn('id', $ids)
            ->select(['id', 'formula'])
            ->get()
            ->each(function (DonationFormula $formula) use (&$basis) {
                $items = [];

                foreach ($this->normalizeItems($formula->formula) as $item) {
                    $items[$item['key']] = $item;
                }

                $basis[$formula->id] = ['items' => $items, 'owed' => []];
            });

        // Only completed, supported-currency donations count; other currencies
        // are excluded so legacy rows are never paid out as USD.
        $donations = DB::table('donations')
            ->whereIn('donation_formula_id', $ids)
            ->where('status', 'completed')
            ->whereRaw('UPPER(currency) = ?', [SupportedCurrency::code()]);

        if ($donationFilter !== null) {
            $donationFilter($donations);
        }

        foreach ($donations->get(['donation_formula_id', 'amount', 'metadata']) as $row) {
            $formulaId = (int) $row->donation_formula_id;

            if (! isset($basis[$formulaId])) {
                continue;
            }

            $metadata = is_string($row->metadata)
                ? json_decode($row->metadata, true)
                : $row->metadata;
            $snapshot = is_array($metadata) ? ($metadata['formula_snapshot'] ?? null) : null;
            $fromSnapshot = is_array($snapshot) && $snapshot !== [];
            $items = $fromSnapshot
                ? $this->normalizeItems($snapshot)
                : array_values($basis[$formulaId]['items']);
            $amount = (float) $row->amount;

            foreach ($items as $item) {
                $key = $item['key'];

                if (! isset($basis[$formulaId]['items'][$key])) {
                    $basis[$formulaId]['items'][$key] = $item;
                }

                $basis[$formulaId]['owed'][$key] = ($basis[$formulaId]['owed'][$key] ?? 0.0)
                    + $amount * ($item['percentage'] / 100);
            }
        }

        foreach ($basis as $formulaId => $data) {
            foreach ($data['owed'] as $key => $owed) {
                $basis[$formulaId]['owed'][$key] = round($owed, 2);
            }
        }

        return $basis;
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
