<?php

namespace App\Services\Payment;

use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single access point for gateway credentials.
 *
 * Credentials live in `payment_methods.credentials` (encrypted at rest with
 * APP_KEY) — .env no longer carries PayPal/Stripe keys. The registry row is
 * the only source of truth, so rotating a key is a DB update with no deploy.
 *
 * Lookups never throw: a missing row or undecryptable value yields an empty
 * set, and the caller that actually needs a credential (e.g. the PayPal token
 * request) fails loudly on that specific key. That keeps webhook endpoints
 * from 500-ing on a cold/partially migrated database.
 *
 * Registered as a singleton, so each method is read (and decrypted) at most
 * once per request.
 */
class PaymentCredentials
{
    /** @var array<string, array<string, mixed>> */
    private array $resolved = [];

    /**
     * @return array<string, mixed>
     */
    public function for(string $code): array
    {
        if (array_key_exists($code, $this->resolved)) {
            return $this->resolved[$code];
        }

        try {
            $stored = PaymentMethod::query()
                ->where('code', $code)
                ->first()
                ?->credentials;
        } catch (Throwable $e) {
            Log::warning('Payment credentials could not be read from the registry.', [
                'method' => $code,
                'error' => $e->getMessage(),
            ]);

            $stored = null;
        }

        return $this->resolved[$code] = is_array($stored) ? $stored : [];
    }

    public function get(string $code, string $key, ?string $default = null): ?string
    {
        $value = $this->for($code)[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public function has(string $code, string $key): bool
    {
        return $this->get($code, $key) !== null;
    }

    /**
     * Credential that is safe to hand to the browser: the PayPal client ID and
     * the Stripe publishable key are public by design.
     *
     * Returns null for any other key — secrets must never take this path.
     */
    public function publicKey(string $code): ?string
    {
        $key = match ($code) {
            PaymentMethod::CODE_PAYPAL => 'client_id',
            PaymentMethod::CODE_STRIPE => 'publishable_key',
            default => null,
        };

        return $key === null ? null : $this->get($code, $key);
    }

    /** Drop the per-request cache (used by tests and long-running workers). */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
