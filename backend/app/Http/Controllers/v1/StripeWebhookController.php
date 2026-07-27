<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Handle incoming Stripe webhook events.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        if (! $webhookSecret) {
            Log::error('Stripe webhook secret not configured');

            return response()->json([
                'success' => false,
                'message' => 'Webhook secret not configured',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], Response::HTTP_UNAUTHORIZED);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook invalid payload: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Invalid payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Log the event for debugging
        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);

        // Handle the event
        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event),
                'checkout.session.expired' => $this->handleCheckoutSessionExpired($event),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
                default => Log::info('Unhandled Stripe webhook event: '.$event->type),
            };
        } catch (\Exception $e) {
            Log::error('Error processing Stripe webhook: '.$e->getMessage(), [
                'event_type' => $event->type,
                'event_id' => $event->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processing webhook',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed',
        ]);
    }

    /**
     * Handle checkout.session.completed event.
     */
    protected function handleCheckoutSessionCompleted($event): void
    {
        $session = $event->data->object;

        $userId = $session->metadata->user_id ?? null;
        $causeId = $session->metadata->cause_id ?? null;

        // Normalize null values that may come through as empty strings or "null" strings
        $userId = $userId && $userId !== '' && $userId !== 'null' ? $userId : null;
        $causeId = $causeId && $causeId !== '' && $causeId !== 'null' ? $causeId : null;
        $amount = ($session->amount_total ?? 0) / 100;
        $currency = $session->currency ?? 'usd';
        $paymentIntentId = $session->payment_intent ?? null;
        $customerId = $session->customer ?? null;

        // Look for existing donation by session ID
        $donation = Donation::where('stripe_session_id', $session->id)->first();

        if (! $donation) {
            // Create new donation record
            $donation = Donation::create([
                'stripe_session_id' => $session->id,
                'stripe_payment_intent_id' => $paymentIntentId,
                'user_id' => $userId,
                'cause_id' => $causeId,
                'donor_name' => $session->customer_details->name ?? null,
                'donor_email' => $session->customer_details->email ?? $session->customer_email ?? null,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
                'metadata' => [
                    'session_id' => $session->id,
                    'payment_status' => $session->payment_status ?? null,
                    'source' => $session->metadata->source ?? 'wikidonate',
                ],
            ]);
        } else {
            // Update existing donation
            $donation->update([
                'stripe_payment_intent_id' => $paymentIntentId,
                'status' => 'completed',
                'metadata' => array_merge($donation->metadata ?? [], [
                    'payment_status' => $session->payment_status ?? null,
                ]),
            ]);
        }

        // Record payment and distribute to NGOs (mirrors DonateController flow)
        $this->recordPaymentAndDistribute($donation, $paymentIntentId, $customerId, $causeId, $userId);

        Log::info('Donation recorded from checkout session', [
            'donation_id' => $donation->id,
            'session_id' => $session->id,
            'amount' => $donation->amount,
        ]);
    }

    /**
     * Record a Payment record and distribute funds to NGOs via PaymentLog.
     * Mirrors the flow in DonateController@donateNow / recordPaymentApi.
     */
    protected function recordPaymentAndDistribute(Donation $donation, ?string $paymentIntentId, ?string $customerId, $causeId, $userId): void
    {
        // Don't create duplicate Payment records
        $existingPayment = Payment::where('payment_id', $paymentIntentId)->first();
        if ($existingPayment) {
            return;
        }

        $payment = Payment::recordPayment([
            'user_id' => $userId,
            'payment_id' => $paymentIntentId ?? $donation->stripe_session_id,
            'customer_id' => $customerId ?? 'checkout_session',
            'amount' => $donation->amount,
            'currency' => $donation->currency,
            'status' => 'succeeded',
            'cause_id' => $causeId,
            'source' => 'stripe_checkout',
        ]);

        // Distribute to NGOs if a cause is specified
        if ($causeId) {
            PaymentLog::distributeAndCreatePaymentLogs($payment, $causeId, $userId);

            Log::info('Payment distributed to NGOs', [
                'payment_id' => $payment->id,
                'cause_id' => $causeId,
                'amount' => $donation->amount,
            ]);
        }
    }

    /**
     * Handle checkout.session.expired event.
     */
    protected function handleCheckoutSessionExpired($event): void
    {
        $session = $event->data->object;

        $donation = Donation::where('stripe_session_id', $session->id)->first();

        if ($donation) {
            $donation->update(['status' => 'expired']);

            Log::info('Donation marked as expired', [
                'donation_id' => $donation->id,
                'session_id' => $session->id,
            ]);
        }
    }

    /**
     * Handle payment_intent.succeeded event.
     */
    protected function handlePaymentIntentSucceeded($event): void
    {
        $paymentIntent = $event->data->object;

        Log::info('Payment intent succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount / 100,
        ]);
    }

    /**
     * Handle payment_intent.payment_failed event.
     */
    protected function handlePaymentIntentFailed($event): void
    {
        $paymentIntent = $event->data->object;

        // Find donation by payment intent ID if we have one
        $donation = Donation::where('stripe_payment_intent_id', $paymentIntent->id)->first();

        if ($donation) {
            $donation->update(['status' => 'failed']);

            Log::info('Donation marked as failed', [
                'donation_id' => $donation->id,
                'payment_intent_id' => $paymentIntent->id,
            ]);
        }

        Log::error('Payment intent failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error->message ?? 'Unknown error',
        ]);
    }
}
