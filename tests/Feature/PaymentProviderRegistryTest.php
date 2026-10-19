<?php

namespace Tests\Feature;

use App\Contracts\PaymentProvider;
use App\Exceptions\PaymentProviderException;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentProviderRegistry;
use App\Services\Payment\Providers\PayPalProvider;
use App\Services\Payment\Providers\StripeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The registry is the seam between the payment_methods table and the provider
 * implementations. Two invariants matter most:
 *
 *  1. resolution is loud when a method is enabled but unbound/has no class —
 *     the donor must get an actionable 4xx/503, never a 500;
 *  2. NEW flows honour `active`, while WEBHOOK handling ignores it so events
 *     for a just-disabled method are still processed.
 */
class PaymentProviderRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): PaymentProviderRegistry
    {
        return app(PaymentProviderRegistry::class);
    }

    public function test_resolves_paypal_provider_by_code(): void
    {
        $provider = $this->registry()->forCode(PaymentMethod::CODE_PAYPAL);

        $this->assertInstanceOf(PaymentProvider::class, $provider);
        $this->assertInstanceOf(PayPalProvider::class, $provider);
        $this->assertSame('paypal', $provider->code());
        $this->assertNotEmpty($provider->supportedCurrencies());
    }

    public function test_inactive_method_is_rejected_for_new_flows(): void
    {
        // Migration seeds stripe with active = 0.
        $this->expectException(PaymentProviderException::class);

        $this->registry()->forCode(PaymentMethod::CODE_STRIPE);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(PaymentProviderException::class);

        $this->registry()->forCode('does-not-exist');
    }

    public function test_enabled_method_without_bound_provider_fails_loudly(): void
    {
        PaymentMethod::create([
            'code' => 'bkash',
            'label' => 'bKash',
            'type' => PaymentMethod::TYPE_INCOME,
            'active' => true,
            'sort_order' => 0,
        ]);

        try {
            $this->registry()->forCode('bkash');
            $this->fail('An enabled method with no provider class must not resolve.');
        } catch (PaymentProviderException $e) {
            // The message must name the offending code so ops can fix the row.
            $this->assertStringContainsString('bkash', $e->getMessage());
        }

        $this->assertFalse($this->registry()->hasBinding('bkash'));
    }

    public function test_webhook_resolution_ignores_active_flag(): void
    {
        // stripe is seeded inactive, yet in-flight webhooks must still resolve.
        $provider = $this->registry()->forWebhook(PaymentMethod::CODE_STRIPE);

        $this->assertInstanceOf(StripeProvider::class, $provider);
        $this->assertSame('stripe', $provider->code());
    }

    public function test_webhook_resolution_rejects_unknown_code(): void
    {
        $this->expectException(PaymentProviderException::class);

        $this->registry()->forWebhook('nope');
    }

    public function test_income_and_payout_lists_respect_type_and_active(): void
    {
        PaymentMethod::create([
            'code' => 'card',
            'label' => 'Card',
            'type' => PaymentMethod::TYPE_INCOME,
            'active' => true,
            'sort_order' => 3,
        ]);

        $registry = $this->registry();

        $incomeCodes = array_map(fn (PaymentMethod $m) => $m->code, $registry->incomeMethods());
        $payoutCodes = array_map(fn (PaymentMethod $m) => $m->code, $registry->payoutMethods());

        // paypal (both) + card (income) are active and income-capable.
        $this->assertSame(['paypal', 'card'], $incomeCodes);

        // Only paypal is payout-capable and active; card and stripe are not.
        $this->assertSame(['paypal'], $payoutCodes);
    }

    public function test_bound_providers_exist_for_seeded_codes(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->hasBinding(PaymentMethod::CODE_PAYPAL));
        $this->assertTrue($registry->hasBinding(PaymentMethod::CODE_STRIPE));
    }
}
