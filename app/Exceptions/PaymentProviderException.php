<?php

namespace App\Exceptions;

/**
 * Raised when a payment method cannot be served: unknown/unbound provider
 * class, inactive method, or an operation a provider does not support.
 *
 * Extends UserFacingException so controllers may surface the message verbatim
 * and clients get an actionable 4xx/503 instead of a generic 500. It is a
 * *loud* failure by design (also logged at critical level) — an enabled method
 * with no provider class must be fixed, not silently ignored.
 */
class PaymentProviderException extends UserFacingException
{
    public static function forMissingProviderClass(string $code, string $class): self
    {
        return new self("Payment method '{$code}' is enabled but its provider class [{$class}] does not exist. Fix the provider binding or disable the method.");
    }

    public static function forUnboundMethod(string $code): self
    {
        return new self("Payment method '{$code}' has no registered provider. Deploy the provider class or disable the method.");
    }

    public static function forInactiveMethod(string $code): self
    {
        return new self("Payment method '{$code}' is not active.");
    }

    public static function unsupported(string $code, string $operation): self
    {
        return new self("Payment method '{$code}' does not support {$operation}.");
    }
}
