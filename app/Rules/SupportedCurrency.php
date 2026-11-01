<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only the platform's single supported currency (USD).
 *
 * The value is compared case-insensitively so `usd`, `USD` and `Usd` all pass;
 * anything else is rejected with a clear message rather than being coerced.
 * The canonical code lives in `config('donations.currency')` — don't hard-code
 * a different list here or in callers.
 */
class SupportedCurrency implements ValidationRule
{
    /**
     * The canonical (uppercase) currency code for this platform.
     */
    public static function code(): string
    {
        return strtoupper((string) config('donations.currency', 'USD'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strtoupper(trim($value)) !== self::code()) {
            $fail('Only :currency donations are supported.')
                ->translate(['currency' => self::code()]);
        }
    }
}
