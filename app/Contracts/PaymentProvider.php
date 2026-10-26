<?php

namespace App\Contracts;

use App\Exceptions\PaymentProviderException;

/**
 * Boundary every pluggable payment method implements.
 *
 * Providers are resolved by the `code` on a `payment_methods` row (see
 * App\Services\Payment\PaymentProviderRegistry); a code with no bound provider
 * class must never be reachable through the donor or payout flows.
 *
 * Credentials are never passed in: implementations read them through
 * App\Services\Payment\PaymentCredentials (the encrypted `credentials` column
 * of the method's registry row) — never from .env.
 *
 * Adding a method = insert a `payment_methods` row + deploy a class that
 * implements this contract and register it in PaymentProviderRegistry.
 */
interface PaymentProvider
{
    /** Registry code this provider is bound to (matches payment_methods.code). */
    public function code(): string;

    /** Human-facing label, sourced from the registry row when available. */
    public function label(): string;

    /** ISO 4217 codes this method can charge in. Empty = no restriction. */
    public function supportedCurrencies(): array;

    /**
     * Start an income (donation) flow for a method.
     *
     * Returns a normalised payload:
     *  - reference: gateway-side id used later for capture/webhook matching
     *  - approval_url | checkout_url: where to send the donor (if any)
     *  - raw: untouched gateway response for logging/debugging
     *
     * @param  array<string, mixed>  $payload
     * @return array{reference: string, approval_url?: ?string, checkout_url?: ?string, raw?: array}
     */
    public function createCheckout(array $payload): array;

    /**
     * Capture / confirm a previously created checkout.
     *
     * @return array<string, mixed> normalised capture data
     */
    public function capture(string $reference): array;

    /**
     * Fetch the current gateway state WITHOUT moving money.
     *
     * Used by return pages that poll a hosted checkout while the webhook is
     * still in flight.
     *
     * @return array<string, mixed> same shape as capture()
     */
    public function retrieve(string $reference): array;

    /**
     * Whether a gateway status means the money actually moved.
     *
     * Providers differ (PayPal `COMPLETED`, Stripe `paid`), so the generic
     * checkout service asks the provider instead of hardcoding statuses.
     */
    public function isCaptured(?string $status): bool;

    /**
     * Whether the donor approves on this site (Smart Buttons) or on a hosted
     * checkout page. Client capture is only offered for the former.
     */
    public function supportsClientCapture(): bool;

    /**
     * Return URL for this gateway, including any placeholder it expands
     * (Stripe appends `{CHECKOUT_SESSION_ID}`).
     */
    public function returnUrl(string $frontendUrl, string $code): string;

    /**
     * Verify an inbound webhook for this method.
     *
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhook(array $headers, string $body): bool;

    /**
     * Send a payout batch (optional capability).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException when unsupported
     */
    public function payout(array $items, string $senderBatchId, string $emailSubject = '', string $emailMessage = ''): array;
}
