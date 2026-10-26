<?php

namespace App\Services\Payment;

use App\Contracts\PaymentProvider;
use App\Exceptions\PaymentProviderException;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\PaymentPendingOrder;
use App\Models\TransactionLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic donor checkout orchestration.
 *
 * The registry decides *whether* a method may be used; the provider class
 * decides *how* to talk to the gateway. This service is the only place that
 * persists the pending order and, on capture, the completed donation — so a
 * new gateway only needs a registry row + a PaymentProvider implementation.
 */
class PaymentCheckoutService
{
    public function __construct(private PaymentProviderRegistry $registry) {}

    /**
     * Start a donor checkout for a method.
     *
     * @param  array<string, mixed>  $input  amount, currency, donor_*, formula_id, details
     * @return array<string, mixed> normalised payload for the SPA
     *
     * @throws PaymentProviderException
     */
    public function create(string $code, array $input, ?int $userId): array
    {
        $provider = $this->registry->forCode($code);

        $amount = round((float) ($input['amount'] ?? 0), 2);
        $currency = strtoupper((string) ($input['currency'] ?? 'USD'));

        $returnUrl = $provider->returnUrl((string) config('app.url'), $code);
        $cancelUrl = rtrim((string) config('app.url'), '/').'/payment/cancel?method='.$code;

        $result = $provider->createCheckout([
            'amount' => $amount,
            'currency' => $currency,
            'return_url' => $returnUrl,
            'success_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'donor_email' => $input['donor_email'] ?? null,
            'metadata' => [
                'invoice_id' => 'WD-'.strtoupper(uniqid()),
            ],
        ]);

        $reference = (string) ($result['reference'] ?? '');

        if ($reference === '') {
            Log::error('Payment provider returned no reference.', [
                'method' => $code,
                'provider' => $provider::class,
            ]);

            throw PaymentProviderException::forMissingReference($code);
        }

        PaymentPendingOrder::create([
            'payment_method' => $code,
            'gateway_reference' => $reference,
            'user_id' => $userId,
            'donation_formula_id' => $input['formula_id'] ?? null,
            'donor_email' => $input['donor_email'] ?? null,
            'donor_name' => $input['donor_name'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'details' => $input['details'] ?? null,
        ]);

        return [
            'payment_method' => $code,
            'reference' => $reference,
            // Back-compat aliases for gateway-shaped clients.
            'order_id' => $code === 'paypal' ? $reference : null,
            'session_id' => $code === 'paypal' ? null : $reference,
            'approval_url' => $result['approval_url'] ?? null,
            'checkout_url' => $result['checkout_url'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

    /**
     * Capture (or confirm) a checkout and record the donation exactly once.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    public function capture(string $code, string $reference): array
    {
        $provider = $this->registry->forCode($code);

        if (! $provider->supportsClientCapture()) {
            throw PaymentProviderException::unsupported($code, 'client-side capture');
        }

        return DB::transaction(function () use ($provider, $code, $reference) {
            // Serialise concurrent captures of the same reference.
            $pending = PaymentPendingOrder::query()
                ->where('payment_method', $code)
                ->where('gateway_reference', $reference)
                ->lockForUpdate()
                ->first();

            // Idempotency: a webhook (or a previous click) may have completed it.
            $existing = Donation::gateway($code, $reference)
                ->where('status', 'completed')
                ->first();

            if ($existing) {
                return $this->successPayload($existing, true);
            }

            if (! $pending) {
                return [
                    'success' => false,
                    'status' => Response::HTTP_NOT_FOUND,
                    'message' => 'Pending order not found',
                    'errors' => ['Order not found or already processed'],
                ];
            }

            $captured = $provider->capture($reference);

            if (! $provider->isCaptured($captured['status'] ?? null)) {
                Log::warning('Payment captured but not completed', [
                    'method' => $code,
                    'reference' => $reference,
                    'status' => $captured['status'] ?? 'UNKNOWN',
                ]);

                return [
                    'success' => false,
                    'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => 'Payment not completed',
                    'errors' => ['The provider has not completed this payment yet.'],
                ];
            }

            $facts = (array) ($captured['capture'] ?? []);

            $formula = $pending->donation_formula_id
                ? DonationFormula::find($pending->donation_formula_id)
                : null;

            $donation = Donation::create([
                'payment_method' => $code,
                'gateway_reference' => $reference,
                'user_id' => $pending->user_id,
                'donation_formula_id' => $pending->donation_formula_id,
                'donor_name' => $pending->donor_name ?: ($facts['payer_name'] ?? null),
                'donor_email' => $pending->donor_email ?: ($facts['payer_email'] ?? null),
                'amount' => $facts['amount'] ?? $pending->amount,
                'currency' => strtoupper((string) ($facts['currency'] ?? $pending->currency)),
                'status' => 'completed',
                'metadata' => [
                    'payment_id' => $facts['payment_id'] ?? $reference,
                    'payer_id' => $facts['payer_id'] ?? null,
                    'source' => $code,
                    'details' => $pending->details,
                    'formula_snapshot' => $formula?->formula,
                    'formula_id' => $pending->donation_formula_id,
                    'formula_uuid' => $formula?->uuid,
                ],
            ]);

            $pending->delete();

            // Admin dashboard aggregates are cached; a new donation must show
            // up immediately (same invalidation as the webhook paths).
            Cache::store('file')->forget('dashboard');

            TransactionLog::record('donation.completed', [
                'subject_type' => Donation::class,
                'subject_id' => $donation->id,
                'message' => sprintf(
                    'Donation of %.2f %s received via %s (%s)',
                    $donation->amount,
                    strtoupper($donation->currency ?? 'USD'),
                    $code,
                    $reference
                ),
                'after' => [
                    'donation_id' => $donation->id,
                    'amount' => (float) $donation->amount,
                    'currency' => strtoupper($donation->currency ?? 'USD'),
                    'provider' => $code,
                    'order_id' => $reference,
                    'status' => 'completed',
                ],
                'meta' => ['payment_method' => $code],
            ]);

            Log::info('Donation recorded from client capture', [
                'donation_id' => $donation->id,
                'payment_method' => $code,
                'gateway_reference' => $reference,
            ]);

            return $this->successPayload($donation, false);
        });
    }

    /**
     * Read-only gateway state for return pages (no donation is written).
     *
     * Uses forWebhook() so a donor who was redirected moments before an admin
     * disabled the method can still see a success page.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    public function status(string $code, string $reference): array
    {
        // Only references this installation created are looked up. Without this
        // guard the public status route would be an oracle: anyone could ask us
        // to fetch arbitrary PayPal order ids / Stripe session ids with our
        // secret credentials, and learn whether they exist.
        $known = Donation::gateway($code, $reference)->exists()
            || PaymentPendingOrder::query()
                ->where('payment_method', $code)
                ->where('gateway_reference', $reference)
                ->exists();

        if (! $known) {
            throw PaymentProviderException::forUnknownReference($code);
        }

        $provider = $this->registry->forWebhook($code);
        $state = $provider->retrieve($reference);
        $donation = Donation::gateway($code, $reference)->first();

        return [
            'payment_method' => $code,
            'reference' => $reference,
            'status' => $state['status'] ?? null,
            'payment_status' => $state['status'] ?? null,
            'completed' => $provider->isCaptured($state['status'] ?? null) || $donation?->isCompleted(),
            'amount' => ($amount = $donation?->amount ?? ($state['capture']['amount'] ?? null)) === null
                ? null
                : (float) $amount,
            'currency' => $donation?->currency
                ?? strtoupper((string) ($state['capture']['currency'] ?? 'USD')),
            'donation_id' => $donation?->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(Donation $donation, bool $existing): array
    {
        return [
            'success' => true,
            'status' => Response::HTTP_OK,
            'message' => $existing ? 'Payment already captured' : 'Payment captured successfully',
            'data' => [
                'donation_id' => $donation->id,
                'payment_method' => $donation->payment_method,
                'reference' => $donation->gateway_reference,
                'status' => 'completed',
                'amount' => (float) $donation->amount,
                'currency' => $donation->currency,
                'already_processed' => $existing,
            ],
        ];
    }
}
