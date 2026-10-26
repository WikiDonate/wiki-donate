<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCredentials;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The PayPal gateway mode and credentials are owned by the payment_methods
 * registry row (config.mode + encrypted credentials) — not by .env.
 */
class PayPalModeRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setCredentials([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_id' => 'test-webhook-id',
        ]);

        Http::fake([
            'api-m.paypal.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'api-m.sandbox.paypal.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
        ]);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function setCredentials(array $credentials): void
    {
        $method = PaymentMethod::query()
            ->where('code', PaymentMethod::CODE_PAYPAL)
            ->firstOrFail();

        $method->forceFill(['credentials' => $credentials])->save();

        app(PaymentCredentials::class)->flush();
    }

    private function setRegistryMode(string $mode): void
    {
        $method = PaymentMethod::query()
            ->where('code', PaymentMethod::CODE_PAYPAL)
            ->firstOrFail();

        $config = $method->config;
        $config['mode'] = $mode;

        $method->forceFill(['config' => $config])->save();
    }

    public function test_registry_mode_drives_the_gateway_endpoint(): void
    {
        $this->setRegistryMode('live');

        app(PayPalClient::class)->getAccessToken();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api-m.paypal.com')
            && ! str_contains($request->url(), 'sandbox'));
    }

    public function test_registry_sandbox_mode_uses_the_sandbox_endpoint(): void
    {
        $this->setRegistryMode('sandbox');

        app(PayPalClient::class)->getAccessToken();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api-m.sandbox.paypal.com'));
    }

    public function test_legacy_config_mode_is_ignored(): void
    {
        // A leftover PAYPAL_MODE / legacy config key must have no effect: the
        // seeded row is sandbox, so the sandbox endpoint is the only correct one.
        config(['services.paypal.mode' => 'live']);

        app(PayPalClient::class)->getAccessToken();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api-m.sandbox.paypal.com'));
    }

    public function test_registry_exposes_the_configured_mode(): void
    {
        $this->setRegistryMode('live');

        $method = PaymentMethod::query()
            ->where('code', PaymentMethod::CODE_PAYPAL)
            ->firstOrFail();

        $this->assertSame('live', $method->mode());
    }

    public function test_credentials_are_read_from_the_registry(): void
    {
        $this->setCredentials([
            'client_id' => 'db-client-id',
            'client_secret' => 'db-client-secret',
        ]);

        app(PayPalClient::class)->getAccessToken();

        Http::assertSent(fn ($request) => $request->hasHeader(
            'Authorization',
            'Basic '.base64_encode('db-client-id:db-client-secret'),
        ));
    }
}
