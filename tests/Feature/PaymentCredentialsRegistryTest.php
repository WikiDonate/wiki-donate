<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gateway credentials live in payment_methods.credentials (encrypted with
 * APP_KEY) and are served to browsers only through the browser-safe
 * `public_key` projection.
 */
class PaymentCredentialsRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $credentials
     */
    private function setCredentials(string $code, array $credentials): PaymentMethod
    {
        $method = PaymentMethod::query()->where('code', $code)->firstOrFail();
        $method->forceFill(['credentials' => $credentials])->save();

        app(PaymentCredentials::class)->flush();

        return $method;
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->setCredentials('paypal', [
            'client_id' => 'live-client-id',
            'client_secret' => 'super-secret-value',
        ]);

        $raw = DB::table('payment_methods')->where('code', 'paypal')->value('credentials');

        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('super-secret-value', $raw);
        $this->assertStringNotContainsString('live-client-id', $raw);

        // …and round-trip back to the original values through the cast.
        $stored = PaymentMethod::query()->where('code', 'paypal')->firstOrFail();

        $this->assertSame('super-secret-value', $stored->credential('client_secret'));
        $this->assertSame('live-client-id', $stored->credential('client_id'));
    }

    public function test_credentials_are_hidden_from_serialization(): void
    {
        $method = $this->setCredentials('paypal', ['client_secret' => 'super-secret-value']);

        $this->assertArrayNotHasKey('credentials', $method->toArray());
        $this->assertStringNotContainsString('super-secret-value', (string) $method->toJson());
    }

    public function test_credentials_service_maps_public_keys_and_never_returns_secrets(): void
    {
        $this->setCredentials('paypal', [
            'client_id' => 'paypal-client-id',
            'client_secret' => 'paypal-secret',
        ]);
        $this->setCredentials('stripe', [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123',
        ]);

        $credentials = app(PaymentCredentials::class);

        $this->assertSame('paypal-client-id', $credentials->publicKey('paypal'));
        $this->assertSame('pk_test_123', $credentials->publicKey('stripe'));
        $this->assertNull($credentials->publicKey('unmapped'));
        $this->assertSame(['client_id' => 'paypal-client-id', 'client_secret' => 'paypal-secret'], $credentials->for('paypal'));
        $this->assertSame('fallback', $credentials->get('paypal', 'missing_key', 'fallback'));
        $this->assertTrue($credentials->has('stripe', 'secret_key'));
        $this->assertFalse($credentials->has('stripe', 'missing_key'));
    }

    public function test_registry_index_exposes_public_key_but_never_secrets(): void
    {
        $this->setCredentials('paypal', [
            'client_id' => 'paypal-client-id',
            'client_secret' => 'paypal-secret-value',
        ]);

        $response = $this->getJson('/api/v1/payment-methods')->assertOk();

        $paypal = collect($response->json('data.methods'))->firstWhere('code', 'paypal');

        $this->assertSame('paypal-client-id', $paypal['public_key']);
        $this->assertStringNotContainsString('paypal-secret-value', $response->getContent());
    }

    public function test_public_key_endpoint_serves_inactive_methods_for_billing(): void
    {
        // Stripe is disabled as a donation method but the billing page still
        // saves cards through it.
        $this->setCredentials('stripe', [
            'publishable_key' => 'pk_test_billing',
            'secret_key' => 'sk_test_billing',
        ]);

        $this->getJson('/api/v1/payment-methods/stripe/public-key')
            ->assertOk()
            ->assertJsonPath('data.code', 'stripe')
            ->assertJsonPath('data.public_key', 'pk_test_billing');
    }

    public function test_public_key_endpoint_rejects_unknown_and_unconfigured_methods(): void
    {
        $this->getJson('/api/v1/payment-methods/does-not-exist/public-key')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        // Registered but no credentials yet.
        $this->getJson('/api/v1/payment-methods/paypal/public-key')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
