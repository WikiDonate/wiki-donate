<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `payment:set-credential` is the supported way to write a gateway credential
 * (e.g. a PayPal webhook ID) into the encrypted registry column without a
 * tinker session. A regression here means webhook signature verification
 * silently reads "missing" and every delivery is rejected.
 */
class SetPaymentCredentialCommandTest extends TestCase
{
    use RefreshDatabase;

    private function paypal(): PaymentMethod
    {
        return PaymentMethod::query()->where('code', PaymentMethod::CODE_PAYPAL)->firstOrFail();
    }

    private function seedPaypalCredentials(): void
    {
        $this->paypal()->forceFill([
            'credentials' => [
                'client_id' => 'sandbox-client-id',
                'client_secret' => 'sandbox-client-secret',
            ],
        ])->save();

        app(PaymentCredentials::class)->flush();
    }

    public function test_it_stores_a_webhook_id_without_removing_the_other_credentials(): void
    {
        $this->seedPaypalCredentials();

        // Resolve the singleton *before* the command so the test also proves
        // the command flushes its per-request cache.
        $credentials = app(PaymentCredentials::class);
        $this->assertNull($credentials->get('paypal', 'webhook_id'));

        $this->artisan('payment:set-credential', [
            'code' => 'paypal',
            'key' => 'webhook_id',
            'value' => 'WH-SANDBOX-123456',
        ])->assertExitCode(0);

        $stored = $this->paypal()->fresh();

        $this->assertSame('WH-SANDBOX-123456', $stored->credential('webhook_id'));
        $this->assertSame('sandbox-client-id', $stored->credential('client_id'));
        $this->assertSame('sandbox-client-secret', $stored->credential('client_secret'));

        // The same singleton instance must see the new value.
        $this->assertSame('WH-SANDBOX-123456', $credentials->get('paypal', 'webhook_id'));
    }

    public function test_it_stores_the_value_encrypted_at_rest(): void
    {
        $this->seedPaypalCredentials();

        $this->artisan('payment:set-credential', [
            'code' => 'paypal',
            'key' => 'webhook_id',
            'value' => 'WH-PLAINTEXT-CHECK',
        ])->assertExitCode(0);

        $raw = (string) DB::table('payment_methods')
            ->where('code', PaymentMethod::CODE_PAYPAL)
            ->value('credentials');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('WH-PLAINTEXT-CHECK', $raw);
    }

    public function test_it_overwrites_an_existing_value(): void
    {
        $this->seedPaypalCredentials();

        $this->artisan('payment:set-credential', [
            'code' => 'paypal',
            'key' => 'webhook_id',
            'value' => 'WH-FIRST',
        ])->assertExitCode(0);

        $this->artisan('payment:set-credential', [
            'code' => 'paypal',
            'key' => 'webhook_id',
            'value' => 'WH-SECOND',
        ])->assertExitCode(0);

        $this->assertSame('WH-SECOND', $this->paypal()->fresh()->credential('webhook_id'));
    }

    public function test_it_rejects_a_typo_in_the_credential_key(): void
    {
        $this->seedPaypalCredentials();

        $this->artisan('payment:set-credential', [
            'code' => 'paypal',
            'key' => 'webhookid',
            'value' => 'WH-TYPO',
        ])->assertExitCode(1);

        $this->assertArrayNotHasKey('webhookid', $this->paypal()->fresh()->credentials ?? []);
    }

    public function test_it_rejects_an_unknown_payment_method(): void
    {
        $this->artisan('payment:set-credential', [
            'code' => 'bogus',
            'key' => 'webhook_id',
            'value' => 'WH-NOPE',
        ])->assertExitCode(1);
    }

    public function test_it_rejects_an_empty_value(): void
    {
        $this->seedPaypalCredentials();

        $this->artisan('payment:set-credential', [
            'code' => 'paypal',
            'key' => 'webhook_id',
            'value' => '   ',
        ])->assertExitCode(1);

        $this->assertNull($this->paypal()->fresh()->credential('webhook_id'));
    }
}
