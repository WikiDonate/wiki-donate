<?php

namespace App\Services\Payment;

use App\Contracts\PaymentProvider;
use App\Exceptions\PaymentProviderException;
use App\Models\PaymentMethod;
use App\Services\Payment\Providers\PayPalProvider;
use App\Services\Payment\Providers\StripeProvider;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a `payment_methods.code` to its provider implementation.
 *
 * The registry row (DB) decides *whether* a method is offered; this class
 * decides *how* it is charged. A code that is enabled but has no bound/invented
 * provider class is a deployment mistake, so resolution fails LOUDLY with a
 * user-facing PaymentProviderException instead of 500-ing the donor flow.
 *
 * New methods: add a row (active=0) + map the code to a provider class here,
 * then flip active=1.
 */
class PaymentProviderRegistry
{
    /**
     * code => provider class binding.
     *
     * @var array<string, class-string<PaymentProvider>>
     */
    private const PROVIDERS = [
        PaymentMethod::CODE_PAYPAL => PayPalProvider::class,
        PaymentMethod::CODE_STRIPE => StripeProvider::class,
    ];

    /** @var array<string, PaymentProvider> */
    private array $resolved = [];

    /**
     * Resolve the provider for a donor/admin-initiated flow.
     *
     * Inactive methods are rejected here: they stay persisted so in-flight
     * webhooks still resolve (see forWebhook) but must never be offered anew.
     *
     * @throws PaymentProviderException
     */
    public function forCode(string $code): PaymentProvider
    {
        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $method = PaymentMethod::query()->where('code', $code)->first();

        if (! $method) {
            throw PaymentProviderException::forUnboundMethod($code);
        }

        if (! $method->active) {
            throw PaymentProviderException::forInactiveMethod($code);
        }

        return $this->resolved[$code] = $this->instantiate($code);
    }

    /**
     * Resolve a provider for an inbound webhook.
     *
     * Unlike forCode() the `active` flag is ignored: a donor may have been
     * redirected to the gateway moments before an admin disabled the method,
     * and that event must still be verified and processed.
     *
     * @throws PaymentProviderException
     */
    public function forWebhook(string $code): PaymentProvider
    {
        return $this->resolved[$code] ??= $this->instantiate($code, requireRow: true);
    }

    /**
     * Active, income-capable methods in display order (donor checkout).
     *
     * @return array<int, PaymentMethod>
     */
    public function incomeMethods(): array
    {
        return PaymentMethod::query()->active()->income()->ordered()->get()->all();
    }

    /**
     * Active, payout-capable methods in display order (admin payouts rail).
     *
     * @return array<int, PaymentMethod>
     */
    public function payoutMethods(): array
    {
        return PaymentMethod::query()->active()->payout()->ordered()->get()->all();
    }

    /**
     * Whether a code has a bound provider whose class actually exists.
     *
     * Used by health checks / tests; never throws.
     */
    public function hasBinding(string $code): bool
    {
        $class = self::PROVIDERS[$code] ?? null;

        return $class !== null && class_exists($class);
    }

    /**
     * Payout execution is bound per method code, separately from income:
     * a method can be payout-capable in the registry yet still lack a rail.
     * A code with no entry here fails LOUDLY at payout creation instead of
     * recording a payout we would never actually send.
     *
     * @var array<string, class-string<PaymentProvider>>
     */
    private const PAYOUT_BINDINGS = [
        PaymentMethod::CODE_PAYPAL => PayPalProvider::class,
    ];

    /**
     * Validate a payout method and return its code.
     *
     * @throws PaymentProviderException when the code is unknown, inactive,
     *                                  not payout-capable, or has no payout rail.
     */
    public function resolvePayoutMethod(?string $code): string
    {
        // Callers that predate the data-driven rail send no code at all;
        // they keep the historical PayPal behavior. An explicitly unknown
        // code still fails loudly below.
        $code = self::normalizeCode($code) ?: PaymentMethod::CODE_PAYPAL;

        $method = PaymentMethod::query()->where('code', $code)->first();

        if (! $method) {
            throw PaymentProviderException::forUnboundMethod($code);
        }

        if (! $method->active) {
            throw PaymentProviderException::forInactiveMethod($method->code);
        }

        if (! $method->isPayoutCapable()) {
            throw PaymentProviderException::unsupported($method->code, 'payouts');
        }

        return $this->instantiatePayout($method->code)->code();
    }

    /**
     * Resolve the payout client for a method code.
     *
     * @throws PaymentProviderException
     */
    public function payoutClient(string $code): PaymentProvider
    {
        return $this->instantiatePayout(self::normalizeCode($code));
    }

    private static function normalizeCode(?string $code): string
    {
        return strtolower(trim((string) $code));
    }

    /**
     * @throws PaymentProviderException
     */
    private function instantiatePayout(string $code): PaymentProvider
    {
        $class = self::PAYOUT_BINDINGS[$code] ?? null;

        if ($class === null) {
            Log::critical('Payout method has no payout rail bound.', ['code' => $code]);

            throw PaymentProviderException::unsupported($code, 'payouts');
        }

        if (! class_exists($class)) {
            Log::critical('Payout client class does not exist.', ['code' => $code, 'class' => $class]);

            throw PaymentProviderException::forMissingProviderClass($code, $class);
        }

        $provider = app($class);

        if (! $provider instanceof PaymentProvider) {
            Log::critical('Payout provider does not implement the contract.', [
                'code' => $code,
                'class' => $class,
            ]);

            throw PaymentProviderException::forMissingProviderClass($code, $class);
        }

        return $provider;
    }

    /**
     * @throws PaymentProviderException
     */
    private function instantiate(string $code, bool $requireRow = false): PaymentProvider
    {
        if ($requireRow && ! PaymentMethod::query()->where('code', $code)->exists()) {
            throw PaymentProviderException::forUnboundMethod($code);
        }

        $class = self::PROVIDERS[$code] ?? null;

        if ($class === null) {
            Log::critical('Payment method has no bound provider class.', ['code' => $code]);

            throw PaymentProviderException::forUnboundMethod($code);
        }

        if (! class_exists($class)) {
            Log::critical('Payment provider class does not exist.', [
                'code' => $code,
                'class' => $class,
            ]);

            throw PaymentProviderException::forMissingProviderClass($code, $class);
        }

        $provider = app($class);

        if (! $provider instanceof PaymentProvider) {
            Log::critical('Payment provider class does not implement the contract.', [
                'code' => $code,
                'class' => $class,
            ]);

            throw PaymentProviderException::forMissingProviderClass($code, $class);
        }

        return $provider;
    }
}
