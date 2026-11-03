<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Models\PaymentPendingOrder;
use App\Models\PaymentWebhookEvent;
use App\Models\TransactionLog;
use App\Rules\SupportedCurrency;
use App\Services\Payment\PaymentCheckoutService;
use App\Services\Payout\PayPalPayoutEvent;
use App\Services\PayPalClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    public function __construct(
        private PayPalClient $paypal,
        private PaymentCheckoutService $checkout,
    ) {}

    /**
     * Handle incoming PayPal webhook events.
     *
     * POST /webhooks/paypal
     * This route is NOT protected by auth middleware.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $body = $request->getContent();

        // Normalize headers to lowercase keys
        $headers = collect($request->headers->all())
            ->map(fn ($values) => $values ?? [])
            ->keyBy(fn ($values, $key) => strtolower($key))
            ->toArray();

        $event = json_decode($body, true);
        $event = is_array($event) ? $event : [];

        // Claim the delivery BEFORE verification, so a forged or replayed
        // request leaves a trace too. Duplicate detection is the unique
        // (provider, event_id) key on this table — not the file cache, which a
        // deploy's cache:clear used to wipe mid-retransmission-window.
        $record = PaymentWebhookEvent::begin('paypal', $event, $headers, $body);

        if ($record?->isFinal()) {
            Log::info('PayPal webhook already processed', ['event_id' => $record->event_id]);

            return response()->json(['success' => true, 'message' => 'Event already processed']);
        }

        // Verify webhook signature
        if (! $this->paypal->verifyWebhook($headers, $body)) {
            Log::warning('PayPal webhook signature verification failed');

            $record?->markSignature(false);
            $record?->markFailed('Signature verification failed', Response::HTTP_UNAUTHORIZED);

            return response()->json([
                'success' => false,
                'message' => 'Webhook verification failed',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $record?->markSignature(true);

        if ($event === []) {
            $record?->markFailed('Invalid webhook payload', Response::HTTP_BAD_REQUEST);

            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // PayPal sends event types in UPPERCASE, e.g. CHECKOUT.ORDER.APPROVED
        $eventType = strtoupper($event['event_type'] ?? '');
        $eventId = $event['id'] ?? '';

        Log::info('PayPal webhook received', [
            'event_type' => $eventType,
            'event_id' => $eventId,
        ]);

        try {
            // Payout notifications are normalized first: PayPal ships two
            // naming families (PAYMENT.PAYOUTS-ITEM.* and PAYOUTS.ITEM.*) and
            // matching on raw strings used to miss every real event.
            $payoutEvent = PayPalPayoutEvent::normalize($eventType);

            if ($payoutEvent !== null) {
                if (str_starts_with($payoutEvent, PayPalPayoutEvent::KIND_BATCH.':')) {
                    $this->handlePayoutBatchEvent($event, $payoutEvent);
                } else {
                    $this->handlePayoutItemEvent($event, $payoutEvent);
                }

                [$relatedType, $relatedId] = $this->relatedFromEvent($eventType, $event);
                $record?->markProcessed(Response::HTTP_OK, $relatedType, $relatedId);

                return response()->json([
                    'success' => true,
                    'message' => 'Webhook processed',
                ]);
            }

            match ($eventType) {
                'CHECKOUT.ORDER.APPROVED' => $this->handleOrderApproved($event),
                'PAYMENT.CAPTURE.COMPLETED' => $this->handleCaptureCompleted($event),
                'PAYMENT.CAPTURE.DENIED' => $this->handleCaptureDenied($event),
                'CHECKOUT.ORDER.CANCELLED' => $this->handleOrderCancelled($event),
                default => Log::info('Unhandled PayPal webhook event: '.$eventType),
            };
        } catch (\Throwable $e) {
            Log::error('Error processing PayPal webhook', [
                'event_type' => $eventType,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            $record?->markFailed($e::class.': '.$e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        [$relatedType, $relatedId] = $this->relatedFromEvent($eventType, $event);
        $record?->markProcessed(Response::HTTP_OK, $relatedType, $relatedId);

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed',
        ]);
    }

    /**
     * Best-effort link from a PayPal event to the ledger row it touched.
     *
     * Runs AFTER the handler, so it only records what actually exists; never
     * throws (a failed lookup must not fail an already-applied event).
     *
     * @return array{0: ?string, 1: ?int}
     */
    protected function relatedFromEvent(string $eventType, array $event): array
    {
        try {
            $resource = $event['resource'] ?? [];

            if (! is_array($resource)) {
                return [null, null];
            }

            // Payout events: PAYMENT.PAYOUTSBATCH.* / PAYMENT.PAYOUTS-ITEM.*
            if (str_starts_with($eventType, 'PAYMENT.PAYOUTS') || str_starts_with($eventType, 'PAYOUTS')
                || str_starts_with($eventType, 'PAYMENT.PAYOUTSBATCH')) {
                $itemId = $resource['payout_item_id'] ?? null;
                $senderItemId = $resource['sender_item_id'] ?? null;

                $payout = $itemId
                    ? OrganizationPayout::where('payout_item_id', $itemId)->first()
                    : ($senderItemId ? OrganizationPayout::where('uuid', $senderItemId)->first() : null);

                return $payout ? [OrganizationPayout::class, $payout->id] : [null, null];
            }

            // Donor captures: the order id is our donation reference.
            if (str_starts_with($eventType, 'PAYMENT.CAPTURE')) {
                $orderId = $resource['supplementary_data']['related_ids']['order_id']
                    ?? $resource['custom_id']
                    ?? null;

                $donation = $orderId ? Donation::gateway('paypal', $orderId)->first() : null;

                return $donation ? [Donation::class, $donation->id] : [null, null];
            }
        } catch (\Throwable $e) {
            Log::warning('PayPal webhook: could not resolve related row', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }

        return [null, null];
    }

    /**
     * Handle batch-level payout events (`batch:<STATUS>`).
     *
     * The per-item truth lives on each payout item (PAYOUTS-ITEM webhooks or
     * polling), so batch events only reconcile rows whose item id we hold.
     */
    protected function handlePayoutBatchEvent(array $event, string $normalized): void
    {
        $resource = $event['resource'] ?? [];
        $batchId = $resource['payout_batch_id'] ?? null;
        if (! $batchId) {
            Log::warning('PayPal webhook: payout batch event missing payout_batch_id', [
                'event_type' => $event['event_type'] ?? null,
            ]);

            return;
        }

        $status = PayPalPayoutEvent::status($normalized);

        // Prefer the payload's own batch_status; fall back to the event name.
        $batchStatus = strtoupper((string) ($resource['batch_status'] ?? $status));

        Log::info('PayPal payout batch event', [
            'event_type' => $event['event_type'] ?? null,
            'payout_batch_id' => $batchId,
            'batch_status' => $batchStatus,
        ]);

        $rows = OrganizationPayout::where('payout_batch_id', $batchId)->get();

        foreach ($rows as $payout) {
            // A batch-level DENIED is definitive: the batch never processed.
            if ($batchStatus === 'DENIED' || ($status === 'DENIED')) {
                $this->markPayoutFailed($payout, 'PayPal denied the payout batch: '.mb_substr((string) ($resource['errors']['message'] ?? 'batch was denied'), 0, 1000));
            }
        }
    }

    /**
     * Handle per-item payout events (`item:<STATUS>`) — the authoritative
     * per-item status. Idempotent: terminal rows are not re-flipped, except
     * for reversals (RETURNED / CANCELED / UNCLAIMED), which arrive AFTER a
     * success and must re-open the balance.
     */
    protected function handlePayoutItemEvent(array $event, string $normalized): void
    {
        $resource = $event['resource'] ?? [];
        $itemId = $resource['payout_item_id'] ?? null;
        $senderItemId = $resource['sender_item_id'] ?? null;

        $payout = $itemId
            ? OrganizationPayout::where('payout_item_id', $itemId)->first()
            : ($senderItemId ? OrganizationPayout::where('uuid', $senderItemId)->first() : null);

        if (! $payout) {
            Log::warning('PayPal webhook: payout item event has no matching ledger row', [
                'event_type' => $event['event_type'] ?? null,
                'payout_item_id' => $itemId,
                'sender_item_id' => $senderItemId,
            ]);

            return;
        }

        $payloadStatus = strtoupper((string) ($resource['transaction_status'] ?? $resource['payout_item_status'] ?? ''));
        // Canonical status from the EVENT NAME wins when the payload omits it.
        $effectiveStatus = $payloadStatus ?: PayPalPayoutEvent::status($normalized);

        // Reversals are handled even for already-paid rows.
        if (PayPalPayoutEvent::isReversal($effectiveStatus)) {
            $this->handlePayoutReversal($payout, $effectiveStatus, (string) (
                $resource['errors']['message'] ?? $resource['errors']['description'] ?? ''
            ));

            return;
        }

        // Idempotency: no-op on already-terminal rows.
        if (in_array($payout->status, OrganizationPayout::TERMINAL_STATUSES, true)) {
            Log::info('PayPal webhook: payout already terminal, ignoring', [
                'payout_id' => $payout->id,
                'status' => $payout->status,
            ]);

            return;
        }

        if ($effectiveStatus === 'SUCCESS' || $effectiveStatus === 'COMPLETED') {
            // Guarded: a concurrent sync/webhook may have already moved the row
            // off `pending`. Only record the confirmation once.
            $affected = OrganizationPayout::query()
                ->whereKey($payout->getKey())
                ->where('status', 'pending')
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payout_item_id' => $payout->payout_item_id ?: ($itemId ?: null),
                    'provider_status' => 'SUCCESS',
                    'failure_reason' => null,
                ]);

            if ($affected === 0) {
                Log::info('PayPal webhook: payout success skipped (row no longer pending)', [
                    'payout_id' => $payout->id,
                ]);

                return;
            }

            $payout->refresh();

            TransactionLog::record('payout.synced', [
                'actor_id' => null,
                'actor_role' => 'System (PayPal webhook)',
                'subject_type' => OrganizationPayout::class,
                'subject_id' => $payout->id,
                'message' => sprintf(
                    'PayPal webhook confirmed payout of %.2f %s gross (net %.2f) to %s',
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

            return;
        }

        if (PayPalPayoutEvent::isFailure($effectiveStatus)) {
            $reason = mb_substr((string) (
                $resource['errors']['message']
                ?? $resource['errors']['description']
                ?? 'PayPal reported a failed payout item ('.$effectiveStatus.')'
            ), 0, 1000);
            $this->markPayoutFailed($payout, $reason, $effectiveStatus);

            return;
        }

        // HELD / PENDING / unknown: keep waiting, store provider status.
        $payout->forceFill([
            'provider_status' => $effectiveStatus,
            'failure_reason' => null,
        ])->save();
    }

    /**
     * Record a post-processing reversal (funds returned to us). The ledger
     * row keeps its amounts; `reversed_at` re-opens the organization balance.
     */
    protected function handlePayoutReversal(OrganizationPayout $payout, string $providerStatus, string $reason = ''): void
    {
        // Already reversed — nothing to do (PayPal retries).
        if ($payout->reversed_at !== null) {
            Log::info('PayPal webhook: payout already reversed, ignoring', [
                'payout_id' => $payout->id,
            ]);

            return;
        }

        // Guarded update: a concurrent sync poll may reverse (or fail) the row
        // between our read and this write. Only transition rows still pending
        // or paid, and only once.
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
            Log::info('PayPal webhook: payout reversal skipped (row no longer reversible)', [
                'payout_id' => $payout->id,
                'status' => $payout->fresh()?->status,
            ]);

            return;
        }

        $payout->refresh();

        TransactionLog::record('payout.reversed', [
            'actor_id' => null,
            'actor_role' => 'System (PayPal webhook)',
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
    }

    /**
     * Flip a ledger row to failed (off-balance so an admin can retry) and
     * record the failure in the transaction log. Idempotent.
     *
     * A reversed row is left untouched: the funds already came back, so
     * flipping it to `failed` would be a lie in the history (and would keep
     * `reversed_at` set on a non-reversed status).
     */
    protected function markPayoutFailed(OrganizationPayout $payout, string $reason, ?string $providerStatus = null): void
    {
        if (in_array($payout->status, ['failed', 'reversed'], true)) {
            return;
        }

        $affected = OrganizationPayout::query()
            ->whereKey($payout->getKey())
            ->whereNotIn('status', ['failed', 'reversed'])
            ->update([
                'status' => 'failed',
                // Keep the TRUE provider status (BLOCKED / RETURNED / ...), not
                // a hard-coded FAILED, so the history explains the rejection.
                'provider_status' => $providerStatus ?: 'FAILED',
                'failure_reason' => $reason,
            ]);

        if ($affected === 0) {
            return;
        }

        $payout->refresh();

        TransactionLog::record('payout.failed', [
            'actor_id' => null,
            'actor_role' => 'System (PayPal webhook)',
            'subject_type' => OrganizationPayout::class,
            'subject_id' => $payout->id,
            'message' => sprintf(
                'Payout of %.2f %s to %s via %s rejected — %s',
                $payout->amount,
                strtoupper($payout->currency),
                $payout->organization_name,
                $payout->payment_method ?: 'paypal',
                $reason
            ),
            'after' => ['status' => 'failed', 'provider_status' => $payout->provider_status, 'failure_reason' => $reason],
            'meta' => ['payment_method' => $payout->payment_method ?: 'paypal'],
        ]);
    }

    /**
     * Handle checkout.order.approved — the buyer authorised the order.
     *
     * This is the recovery path for a donor who approves at PayPal and closes
     * the tab before the SPA can call capture: without capturing here the
     * authorisation silently expires and the money is never taken. Capture goes
     * through PaymentCheckoutService, the same idempotent path the SPA uses, so
     * a simultaneous client-side capture cannot charge twice (the provider
     * reads the order back when PayPal answers ORDER_ALREADY_CAPTURED).
     */
    protected function handleOrderApproved(array $event): void
    {
        $orderId = $event['resource']['id'] ?? null;

        if (! $orderId) {
            Log::warning('PayPal webhook: checkout.order.approved missing order ID');

            return;
        }

        $pending = PaymentPendingOrder::query()
            ->where('payment_method', 'paypal')
            ->where('gateway_reference', $orderId)
            ->first();

        // No in-flight checkout (already captured, already closed, or created
        // by another installation): nothing we can safely capture.
        if (! $pending || ! $pending->isOpen()) {
            Log::info('PayPal order approved with nothing to capture', [
                'order_id' => $orderId,
                'status' => $pending?->status,
            ]);

            return;
        }

        // Exceptions bubble up: PayPal retries a 500, which is exactly what we
        // want for a transient gateway failure.
        $result = $this->checkout->capture('paypal', (string) $orderId);

        if (! ($result['success'] ?? false)) {
            // A declined/uncompletable capture is not retryable; the donor (or
            // the PAYMENT.CAPTURE.DENIED webhook) closes the checkout.
            Log::warning('PayPal webhook: capture on approval did not complete', [
                'order_id' => $orderId,
                'message' => $result['message'] ?? null,
            ]);

            return;
        }

        Log::info('PayPal order captured from approval webhook', [
            'order_id' => $orderId,
            'donation_id' => $result['data']['donation_id'] ?? null,
        ]);
    }

    /**
     * Handle payment.capture.completed — funds have been captured.
     */
    protected function handleCaptureCompleted(array $event): void
    {
        $resource = $event['resource'] ?? [];

        // The order ID lives in supplementary_data.related_ids.order_id.
        // We deliberately ignore custom_id/invoice_id here because those are
        // our own internal values (e.g. invoice_id is "WD-<uniqid>") and would
        // misattribute the donation to the wrong order.
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['custom_id'] ?? null;

        if (! $orderId) {
            Log::warning('PayPal webhook: payment.capture.completed missing order ID');

            return;
        }

        // Idempotency: skip if already recorded
        $existing = Donation::gateway('paypal', $orderId)
            ->where('status', 'completed')
            ->first();

        if ($existing) {
            Log::info('PayPal capture already recorded', [
                'order_id' => $orderId,
                'donation_id' => $existing->id,
            ]);

            return;
        }

        // Look up the pending order
        $pending = PaymentPendingOrder::where('payment_method', 'paypal')
            ->where('gateway_reference', $orderId)
            ->first();

        // The capture webhook resource contains the merchant (payee), not the
        // buyer (payer). Pull payer details from the pending order if present,
        // otherwise fetch the full order from PayPal to get updated totals.
        $donorName = $pending?->donor_name;
        $donorEmail = $pending?->donor_email;

        $captureData = $this->extractCaptureFromResource($resource);

        if (! $donorName || ! $donorEmail) {
            try {
                $order = $this->paypal->showOrder($orderId);
                $orderData = PayPalClient::extractCaptureData($order);
                $captureData['amount'] = $orderData['amount'] ?: $captureData['amount'];
                $captureData['currency'] = $orderData['currency'] ?: $captureData['currency'];
                $donorName = $donorName ?: $orderData['payer_name'] ?: null;
                $donorEmail = $donorEmail ?: $orderData['payer_email'] ?: null;
            } catch (\Throwable $e) {
                Log::warning('PayPal webhook: could not fetch order for payer details', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $formula = $pending?->donation_formula_id ? DonationFormula::find($pending->donation_formula_id) : null;

        // The platform is USD-only. Every order is created in that currency, so
        // a non-USD capture means provider drift/tampering; record it in the
        // supported currency (the ledger cannot hold a second one) and leave an
        // audit trail instead of silently dropping an already-captured payment.
        $capturedCurrency = strtoupper(trim((string) ($captureData['currency'] ?? '')));
        $recordedCurrency = SupportedCurrency::code();
        if ($capturedCurrency !== '' && $capturedCurrency !== $recordedCurrency) {
            Log::warning('PayPal webhook: capture currency is not the supported currency', [
                'order_id' => $orderId,
                'captured_currency' => $capturedCurrency,
                'recorded_currency' => $recordedCurrency,
            ]);
        }

        $donation = Donation::create([
            'payment_method' => 'paypal',
            'gateway_reference' => $orderId,
            'user_id' => $pending?->user_id,
            'donation_formula_id' => $pending?->donation_formula_id,
            'donor_name' => $donorName,
            'donor_email' => $donorEmail,
            'amount' => $captureData['amount'] ?? 0,
            'currency' => $recordedCurrency,
            'status' => 'completed',
            'metadata' => [
                'payment_id' => $captureData['payment_id'] ?? null,
                'source' => 'paypal_webhook',
                'details' => $pending?->details,
                'formula_snapshot' => $formula?->formula,
                'formula_id' => $pending?->donation_formula_id,
                'formula_uuid' => $formula?->uuid,
            ],
        ]);

        if ($pending) {
            $pending->delete();
        }

        Cache::store('file')->forget('dashboard');

        // Report-friendly income transaction: single log for financial reports.
        TransactionLog::record('donation.completed', [
            'subject_type' => Donation::class,
            'subject_id' => $donation->id,
            'message' => sprintf(
                'Donation of %.2f %s received via PayPal (%s)',
                $donation->amount,
                strtoupper($donation->currency ?? 'USD'),
                $orderId
            ),
            'after' => [
                'donation_id' => $donation->id,
                'amount' => (float) $donation->amount,
                'currency' => strtoupper($donation->currency ?? 'USD'),
                'provider' => 'paypal',
                'order_id' => $orderId,
                'status' => 'completed',
            ],
            'meta' => ['payment_method' => 'paypal'],
        ]);

        Log::info('PayPal donation recorded via webhook', [
            'order_id' => $orderId,
            'donation_id' => $donation->id,
            'amount' => $captureData['amount'] ?? 0,
        ]);
    }

    /**
     * Handle payment.capture.denied — the gateway declined the capture.
     *
     * The checkout is closed, not deleted: the donor's return page must be
     * able to answer "declined" instead of 404 (which the SPA used to render
     * as a false success), and admins get an audit row for the attempt.
     */
    protected function handleCaptureDenied(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['custom_id'] ?? null;

        if (! $orderId) {
            Log::warning('PayPal webhook: payment.capture.denied missing order ID');

            return;
        }

        $reason = $resource['status_details']['reason'] ?? null;

        $this->closePendingOrder(
            (string) $orderId,
            PaymentPendingOrder::STATUS_FAILED,
            $reason !== null ? (string) $reason : 'DECLINED',
            'PAYMENT.CAPTURE.DENIED'
        );
    }

    /**
     * Handle checkout.order.cancelled — buyer abandoned/cancelled the order.
     *
     * Closed, not deleted, for the same reason as a denied capture.
     */
    protected function handleOrderCancelled(array $event): void
    {
        $orderId = $event['resource']['id'] ?? null;

        if (! $orderId) {
            Log::warning('PayPal webhook: checkout.order.cancelled missing order ID');

            return;
        }

        $this->closePendingOrder(
            (string) $orderId,
            PaymentPendingOrder::STATUS_CANCELLED,
            'CANCELLED',
            'CHECKOUT.ORDER.CANCELLED'
        );
    }

    /**
     * Close an in-flight checkout with a terminal status.
     *
     * Idempotent: a second event for the same order (or an order already
     * captured) leaves the row untouched.
     */
    protected function closePendingOrder(string $orderId, string $status, ?string $reason, string $eventType): void
    {
        $pending = PaymentPendingOrder::query()
            ->where('payment_method', 'paypal')
            ->where('gateway_reference', $orderId)
            ->first();

        if (! $pending) {
            Log::info('PayPal webhook: no in-flight checkout to close', [
                'order_id' => $orderId,
                'event_type' => $eventType,
            ]);

            return;
        }

        if (! $pending->isOpen()) {
            Log::info('PayPal webhook: checkout already closed', [
                'order_id' => $orderId,
                'status' => $pending->status,
            ]);

            return;
        }

        $pending->forceFill([
            'status' => $status,
            'status_reason' => $reason,
        ])->save();

        // The audit trail is what makes a failed attempt visible at all:
        // pending orders never appear in donation reports.
        TransactionLog::record('payment.'.$status, [
            'actor_role' => 'Webhook',
            'message' => sprintf('PayPal %s for order %s', $status, $orderId),
            'after' => [
                'payment_method' => 'paypal',
                'order_id' => $orderId,
                'status' => $status,
                'reason' => $reason,
            ],
            'meta' => [
                'event_type' => $eventType,
                'gateway_reference' => $orderId,
            ],
        ]);

        Log::info('PayPal checkout closed', [
            'order_id' => $orderId,
            'status' => $status,
            'reason' => $reason,
            'event_type' => $eventType,
        ]);
    }

    /**
     * Extract capture data from a PayPal webhook resource (capture object).
     */
    protected function extractCaptureFromResource(array $resource): array
    {
        $amount = $resource['amount'] ?? [];
        $payer = $resource['payer'] ?? [];
        $name = $payer['name'] ?? [];

        return [
            'amount' => (float) ($amount['value'] ?? 0),
            'currency' => $amount['currency_code'] ?? 'USD',
            'payer_email' => $payer['email_address'] ?? null,
            'payer_name' => trim(($name['given_name'] ?? '').' '.($name['surname'] ?? '')),
            'payment_id' => $resource['id'] ?? null,
        ];
    }
}
