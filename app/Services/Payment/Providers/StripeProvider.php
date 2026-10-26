<?php

namespace App\Services\Payment\Providers;

use App\Contracts\PaymentProvider;
use App\Exceptions\PaymentProviderException;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCredentials;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Stripe implementation of the payment-provider boundary.
 *
 * Sibling of PayPalProvider: all Stripe vocabulary (sessions, payment intents)
 * stays inside this class so generic code only ever sees references and
 * normalised payloads.
 *
 * Credentials come from the encrypted payment_methods registry row
 * (App\Services\Payment\PaymentCredentials), never from .env.
 */
class StripeProvider implements PaymentProvider
{
    public function __construct(private PaymentCredentials $credentials)
    {
        // Never fatal here: webhook verification does not need the API key, and
        // the registry resolves providers even for methods whose credentials
        // are not configured yet. Operations that do need it guard below.
        if ($key = $this->credentials->get(PaymentMethod::CODE_STRIPE, 'secret_key')) {
            Stripe::setApiKey($key);
        } else {
            Log::warning('Stripe secret key is not configured in the payment_methods registry.');
        }
    }

    /**
     * Fail as a controlled PaymentProviderException (never a 500 leaking the
     * Stripe SDK error), and require the credential before any API call.
     */
    private function applyApiKey(): void
    {
        Stripe::setApiKey(
            $this->credentials->get(PaymentMethod::CODE_STRIPE, 'secret_key')
                ?? throw PaymentProviderException::forMissingCredentials(
                    PaymentMethod::CODE_STRIPE,
                    'secret_key'
                )
        );
    }

    /**
     * Run one Stripe API call, translating SDK errors into the provider
     * boundary's 502. The detail stays in the log — never in the response.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws PaymentProviderException
     */
    private function gatewayCall(callable $callback)
    {
        try {
            return $callback();
        } catch (ApiErrorException $e) {
            Log::error('Stripe API request failed.', [
                'method' => PaymentMethod::CODE_STRIPE,
                'error' => $e->getMessage(),
            ]);

            throw PaymentProviderException::gatewayError(PaymentMethod::CODE_STRIPE);
        }
    }

    public function code(): string
    {
        return PaymentMethod::CODE_STRIPE;
    }

    public function label(): string
    {
        return 'Stripe';
    }

    /**
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP'];
    }

    /**
     * Create a hosted Checkout Session. Stripe handles the donor redirect
     * itself, so `checkout_url` (not `approval_url`) is the populated field.
     *
     * @param  array<string, mixed>  $payload
     * @return array{reference: string, approval_url: null, checkout_url: ?string, raw: array}
     */
    public function createCheckout(array $payload): array
    {
        $this->applyApiKey();

        $amount = (float) ($payload['amount'] ?? 0);
        $currency = strtolower((string) ($payload['currency'] ?? 'usd'));

        $config = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => (string) ($payload['line_item_name'] ?? 'Donation to WikiDonate'),
                    ],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => (string) ($payload['success_url'] ?? $payload['return_url'] ?? ''),
            'cancel_url' => (string) ($payload['cancel_url'] ?? ''),
            // Stripe metadata values must be scalar; arrays are JSON-encoded.
            'metadata' => $this->stringifyMetadata((array) ($payload['metadata'] ?? [])),
        ];

        if (! empty($payload['donor_email'])) {
            $config['customer_email'] = (string) $payload['donor_email'];
        }

        $session = $this->gatewayCall(fn () => Session::create($config));

        return [
            'reference' => (string) $session->id,
            'approval_url' => null,
            'checkout_url' => $session->url,
            'raw' => $session->toArray(),
        ];
    }

    /**
     * Retrieve a checkout session (Stripe's confirmation step is a fetch, not
     * an explicit capture).
     *
     * @return array{reference: string, status: ?string, capture: array<string, mixed>, raw: array}
     */
    public function capture(string $reference): array
    {
        $this->applyApiKey();

        $session = $this->gatewayCall(fn () => Session::retrieve($reference));

        $details = $session->customer_details ?? null;

        $capture = [
            'amount' => (int) ($session->amount_total ?? 0) / 100,
            'currency' => strtoupper((string) ($session->currency ?? 'usd')),
            'payment_id' => $session->payment_intent ?? null,
            'payer_email' => $session->customer_email ?? ($details->email ?? null),
            'payer_name' => $details->name ?? null,
            'order_status' => $session->payment_status ?? 'unknown',
            'create_time' => isset($session->created) ? date('c', (int) $session->created) : null,
        ];

        return [
            'reference' => $reference,
            'status' => $session->payment_status ?? null,
            'capture' => $capture,
            'raw' => $session->toArray(),
        ];
    }

    public function retrieve(string $reference): array
    {
        // Stripe's confirmation step is a fetch, so capture() is side-effect free.
        return $this->capture($reference);
    }

    public function isCaptured(?string $status): bool
    {
        return in_array((string) $status, ['paid', 'no_payment_required'], true);
    }

    public function supportsClientCapture(): bool
    {
        // Hosted Checkout: Stripe confirms out-of-band (webhook), never by a
        // client-side capture call.
        return false;
    }

    public function returnUrl(string $frontendUrl, string $code): string
    {
        return rtrim($frontendUrl, '/').'/payment/success?method='.$code
            .'&session_id={CHECKOUT_SESSION_ID}';
    }

    public function verifyWebhook(array $headers, string $body): bool
    {
        $secret = $this->credentials->get(PaymentMethod::CODE_STRIPE, 'webhook_secret');

        if (! $secret) {
            Log::error('Stripe webhook secret not configured — rejecting event.');

            return false;
        }

        $signature = $this->header($headers, 'stripe-signature');

        if (! $signature) {
            Log::warning('Stripe webhook missing signature header.');

            return false;
        }

        try {
            Webhook::constructEvent($body, $signature, $secret);

            return true;
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed: '.$e->getMessage());

            return false;
        } catch (\Throwable $e) {
            Log::error('Stripe webhook verification error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Stripe has no payout capability in this app — fail loud, never silently.
     *
     * @throws PaymentProviderException
     */
    public function payout(array $items, string $senderBatchId, string $emailSubject = '', string $emailMessage = ''): array
    {
        throw PaymentProviderException::unsupported($this->code(), 'payouts');
    }

    /**
     * Case-insensitive header lookup that tolerates Symfony header bags.
     *
     * @param  array<string, mixed>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }

            $value = is_array($value) ? ($value[0] ?? null) : $value;

            return $value === null ? null : (string) $value;
        }

        return null;
    }

    /**
     * Stripe rejects nested metadata, so encode arrays as JSON strings.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    private function stringifyMetadata(array $metadata): array
    {
        $flat = [];

        foreach ($metadata as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            $flat[(string) $key] = (string) $value;
        }

        return $flat;
    }
}
