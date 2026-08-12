<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\PayPalPendingOrder;
use App\Services\PayPalClient;
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
    public function handleWebhook(Request $request): Response
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

        $eventType = $event['event_type'] ?? '';
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
                'checkout.order.approved' => $this->handleOrderApproved($event),
                'payment.capture.completed' => $this->handleCaptureCompleted($event),
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
        $resource = $event['resource'];

        // The order ID is in supplementary_data.related_ids
        $orderId = $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['custom_id']
            ?? $resource['invoice_id']
            ?? null;

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

        // Extract capture data from the webhook resource
        $captureData = $this->extractCaptureFromResource($resource);

        Donation::create([
            'paypal_order_id' => $orderId,
            'user_id' => $pending?->user_id,
            'donor_name' => $pending?->donor_name ?: ($captureData['payer_name'] ?? null),
            'donor_email' => $pending?->donor_email ?: ($captureData['payer_email'] ?? null),
            'amount' => $captureData['amount'] ?? 0,
            'currency' => $captureData['currency'] ?? 'USD',
            'status' => 'completed',
            'metadata' => [
                'payment_id' => $captureData['payment_id'] ?? null,
                'source' => 'paypal_webhook',
                'formula' => $pending?->formula,
                'details' => $pending?->details,
            ],
        ]);

        if ($pending) {
            $pending->delete();
        }

        Cache::store('file')->forget('dashboard');

        Log::info('PayPal donation recorded via webhook', [
            'order_id' => $orderId,
            'amount' => $captureData['amount'] ?? 0,
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
