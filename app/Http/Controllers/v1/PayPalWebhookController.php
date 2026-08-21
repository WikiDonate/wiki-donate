<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\PayPalPendingOrder;
use App\Services\PayPalClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    private PayPalClient $paypal;

    public function __construct(PayPalClient $paypal)
    {
        $this->paypal = $paypal;
    }

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

        // Verify webhook signature
        if (! $this->paypal->verifyWebhook($headers, $body)) {
            Log::warning('PayPal webhook signature verification failed');

            return response()->json([
                'success' => false,
                'message' => 'Webhook verification failed',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $event = json_decode($body, true);

        if (! $event) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // PayPal sends event types in UPPERCASE, e.g. CHECKOUT.ORDER.APPROVED
        $eventType = strtoupper($event['event_type'] ?? '');
        $eventId = $event['id'] ?? '';

        // Idempotency: skip already-processed events
        if ($this->alreadyProcessed($eventId)) {
            Log::info('PayPal webhook already processed', ['event_id' => $eventId]);

            return response()->json(['success' => true, 'message' => 'Event already processed']);
        }

        Log::info('PayPal webhook received', [
            'event_type' => $eventType,
            'event_id' => $eventId,
        ]);

        try {
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

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->markProcessed($eventId);

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed',
        ]);
    }

    /**
     * Handle checkout.order.approved — buyer has approved the order.
     * For IMMEDIATE_PAYMENT_REQUIRED, capture confirmation comes via
     * payment.capture.completed, so this just logs.
     */
    protected function handleOrderApproved(array $event): void
    {
        $orderId = $event['resource']['id'] ?? null;

        if (! $orderId) {
            Log::warning('PayPal webhook: checkout.order.approved missing order ID');

            return;
        }

        Log::info('PayPal order approved (webhook)', ['order_id' => $orderId]);
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
        $existing = Donation::where('paypal_order_id', $orderId)
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
        $pending = PayPalPendingOrder::where('paypal_order_id', $orderId)->first();

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

        $donation = Donation::create([
            'paypal_order_id' => $orderId,
            'user_id' => $pending?->user_id,
            'donation_formula_id' => $pending?->donation_formula_id,
            'donor_name' => $donorName,
            'donor_email' => $donorEmail,
            'amount' => $captureData['amount'] ?? 0,
            'currency' => $captureData['currency'] ?? 'USD',
            'status' => 'completed',
            'metadata' => [
                'payment_id' => $captureData['payment_id'] ?? null,
                'source' => 'paypal_webhook',
                'details' => $pending?->details,
            ],
        ]);

        if ($pending) {
            $pending->delete();
        }

        Cache::store('file')->forget('dashboard');

        Log::info('PayPal donation recorded via webhook', [
            'order_id' => $orderId,
            'donation_id' => $donation->id,
            'amount' => $captureData['amount'] ?? 0,
        ]);
    }

    /**
     * Handle payment.capture.denied — the capture was declined, nothing to charge.
     * Clean up the pending order so it does not linger.
     */
    protected function handleCaptureDenied(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? $resource['custom_id'] ?? null;

        if ($orderId) {
            PayPalPendingOrder::where('paypal_order_id', $orderId)->delete();
            Log::info('PayPal capture denied, pending order removed', ['order_id' => $orderId]);
        }
    }

    /**
     * Handle checkout.order.cancelled — buyer abandoned/cancelled the order.
     * Remove the pending order so abandoned checkouts are cleaned up.
     */
    protected function handleOrderCancelled(array $event): void
    {
        $orderId = $event['resource']['id'] ?? null;

        if ($orderId) {
            PayPalPendingOrder::where('paypal_order_id', $orderId)->delete();
            Log::info('PayPal order cancelled, pending order removed', ['order_id' => $orderId]);
        }
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

    /**
     * Check if a webhook event has already been processed (idempotency).
     */
    protected function alreadyProcessed(string $eventId): bool
    {
        return Cache::store('file')->has("paypal_webhook:{$eventId}");
    }

    /**
     * Mark a webhook event as processed for idempotency.
     * 24-hour TTL covers PayPal's retransmission window.
     */
    protected function markProcessed(string $eventId): void
    {
        Cache::store('file')->put("paypal_webhook:{$eventId}", true, now()->addHours(24));
    }
}
