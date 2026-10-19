<?php

namespace App\Contracts;

/**
 * Boundary every pluggable payment method implements.
 *
 * Providers are resolved by the `code` on a `payment_methods` row (see
 * App\Services\Payment\PaymentProviderRegistry); a code with no bound provider
 * class must never be reachable through the donor or payout flows.
 *
 * Credentials are never passed in: implementations read them from
 * config('services.*') / env, using the env-var NAME recorded in the row's
 * non-secret `config` JSON only when they need to distinguish environments.
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
     * @throws \App\Exceptions\PaymentProviderException when unsupported
     */
    public function payout(array $items, string $senderBatchId, string $emailSubject = '', string $emailMessage = ''): array;
}
