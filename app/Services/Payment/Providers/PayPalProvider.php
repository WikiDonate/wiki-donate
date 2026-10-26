<?php

namespace App\Services\Payment\Providers;

use App\Contracts\PaymentProvider;
use App\Models\PaymentMethod;
use App\Services\PayPalClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * PayPal implementation of the payment-provider boundary.
 *
 * This is the ONLY place allowed to speak PayPal-specific vocabulary; the
 * generic controllers/services above it (PaymentController, registry) deal in
 * codes, references and normalised payloads.
 *
 * Credentials come from the encrypted payment_methods registry row (see
 * App\Services\Payment\PaymentCredentials) — never from .env. The row's
 * non-secret `config` column only carries metadata such as the gateway mode.
 */
class PayPalProvider implements PaymentProvider
{
    public function __construct(private PayPalClient $client) {}

    public function code(): string
    {
        return PaymentMethod::CODE_PAYPAL;
    }

    public function label(): string
    {
        return 'PayPal';
    }

    /**
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'AUD', 'CAD'];
    }

    /**
     * Create a CAPTURE-intent PayPal order and expose the donor approval link.
     *
     * @param  array<string, mixed>  $payload  amount, currency, return_url, cancel_url, metadata
     * @return array{reference: string, approval_url: ?string, checkout_url: null, raw: array}
     */
    public function createCheckout(array $payload): array
    {
        $order = $this->client->createOrder(
            (float) ($payload['amount'] ?? 0),
            (string) ($payload['currency'] ?? 'USD'),
            (string) ($payload['return_url'] ?? ''),
            (string) ($payload['cancel_url'] ?? ''),
            (array) ($payload['metadata'] ?? [])
        );

        return [
            'reference' => (string) ($order['id'] ?? ''),
            'approval_url' => $this->approvalUrl($order),
            'checkout_url' => null,
            'raw' => $order,
        ];
    }

    /**
     * Capture an approved order and flatten the payer/amount facts callers need.
     *
     * @return array{reference: string, status: ?string, capture: array<string, mixed>, raw: array}
     */
    public function capture(string $reference): array
    {
        try {
            $order = $this->client->captureOrder($reference);
        } catch (RequestException $e) {
            // A capture race (double click, webhook ahead of the client) makes
            // PayPal answer ORDER_ALREADY_CAPTURED. The money moved, so read
            // the order back instead of losing the donation.
            if (! $this->alreadyCaptured($e)) {
                throw $e;
            }

            Log::info('PayPal order already captured — reading it back.', [
                'reference' => $reference,
            ]);

            $order = $this->client->showOrder($reference);
        }

        return [
            'reference' => $reference,
            'status' => $order['status'] ?? null,
            'capture' => PayPalClient::extractCaptureData($order),
            'raw' => $order,
        ];
    }

    /**
     * Read the order without capturing it (return-page polling).
     */
    public function retrieve(string $reference): array
    {
        $order = $this->client->showOrder($reference);

        return [
            'reference' => $reference,
            'status' => $order['status'] ?? null,
            'capture' => PayPalClient::extractCaptureData($order),
            'raw' => $order,
        ];
    }

    public function isCaptured(?string $status): bool
    {
        return strtoupper((string) $status) === 'COMPLETED';
    }

    public function supportsClientCapture(): bool
    {
        // The donor approves inside PayPal's Smart Buttons on our page.
        return true;
    }

    public function returnUrl(string $frontendUrl, string $code): string
    {
        return rtrim($frontendUrl, '/').'/payment/success?method='.$code;
    }

    /**
     * Detect PayPal's ORDER_ALREADY_CAPTURED error family.
     */
    private function alreadyCaptured(RequestException $e): bool
    {
        $details = $e->response?->json('details') ?? [];

        foreach ($details as $detail) {
            $issue = strtoupper((string) ($detail['issue'] ?? ''));

            if (str_contains($issue, 'ALREADY_CAPTURED')) {
                return true;
            }
        }

        return false;
    }

    public function verifyWebhook(array $headers, string $body): bool
    {
        return $this->client->verifyWebhook($this->normaliseHeaders($headers), $body);
    }

    /**
     * Send one payout batch (each dashboard Pay action = one batch).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function payout(array $items, string $senderBatchId, string $emailSubject = '', string $emailMessage = ''): array
    {
        return $this->client->createBatchPayout($items, $senderBatchId, $emailSubject, $emailMessage);
    }

    /**
     * Pull the donor approval link out of the order's HAL links.
     *
     * @param  array<string, mixed>  $order
     */
    private function approvalUrl(array $order): ?string
    {
        foreach ($order['links'] ?? [] as $link) {
            $rel = $link['rel'] ?? null;

            if (in_array($rel, ['approve', 'payer-action'], true) && ! empty($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }

    /**
     * PayPalClient expects lower-cased header names with array values.
     *
     * Accepts either Symfony's header bag (values already arrays) or a flat
     * map, so both controllers and tests can call this.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, array<int, string>>
     */
    private function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = array_map(
                static fn ($item) => (string) $item,
                is_array($value) ? $value : [$value]
            );
        }

        return $normalised;
    }
}
