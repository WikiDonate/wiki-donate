<?php

namespace App\Exceptions;

use Illuminate\Http\Response;

/**
 * Raised when a payment method cannot be served: unknown/unbound provider
 * class, inactive method, a missing credential, a gateway failure, or an
 * operation a provider does not support.
 *
 * Extends UserFacingException so controllers may surface the message verbatim
 * and clients get an actionable 4xx/5xx instead of a generic 500. It is a *loud*
 * failure by design (also logged at critical level) — an enabled method with no
 * provider class must be fixed, not silently ignored.
 *
 * Each factory carries the HTTP status the generic endpoint should answer with,
 * so controllers never have to pattern-match exception text.
 */
class PaymentProviderException extends UserFacingException
{
    public function __construct(string $message, private int $httpStatus = Response::HTTP_NOT_FOUND)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

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
        return new self(
            "Payment method '{$code}' does not support {$operation}.",
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    public static function forMissingCredentials(string $code, string $key): self
    {
        return new self(
            "Payment method '{$code}' is missing the '{$key}' credential. Add it to the payment_methods registry (credentials column).",
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    public static function forMissingReference(string $code): self
    {
        return new self(
            "Payment method '{$code}' did not return a gateway reference. Check the provider implementation.",
            Response::HTTP_BAD_GATEWAY
        );
    }

    /**
     * The gateway refused/failed the request (declined card, outage, bad key).
     * The caller gets a generic 502; provider detail stays in the log.
     */
    public static function gatewayError(string $code): self
    {
        return new self(
            "Payment method '{$code}' could not complete the request with its gateway.",
            Response::HTTP_BAD_GATEWAY
        );
    }

    /**
     * No local pending order or donation owns this gateway reference.
     *
     * Read-only status lookups use this so the endpoint can never be used as an
     * oracle to probe arbitrary PayPal order ids / Stripe session ids with our
     * secret key.
     */
    public static function forUnknownReference(string $code): self
    {
        return new self(
            "No payment is known for that reference under payment method '{$code}'.",
            Response::HTTP_NOT_FOUND
        );
    }
}
