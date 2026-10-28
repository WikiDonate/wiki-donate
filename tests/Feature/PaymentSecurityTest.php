<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\PaymentMethod;
use App\Models\PaymentPendingOrder;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\Payment\PaymentCredentials;
use App\Services\Payment\Providers\PayPalProvider;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Security regressions for the pluggable payment-method refactor.
 *
 * The payment surface is public by necessity (guest checkout, gateway
 * callbacks), so these tests pin the controls that keep it from becoming an
 * oracle, an open redirect, or a credential leak.
 */
class PaymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function paypalMock(): MockInterface
    {
        $mock = Mockery::mock(PayPalClient::class);
        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    private function adminHeader(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin']));

        return ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken];
    }

    // ---------------------------------------------------------------------
    // STATUS ENDPOINT — local references only, read-only
    // ---------------------------------------------------------------------

    public function test_status_endpoint_rejects_unknown_reference_without_calling_the_gateway(): void
    {
        // If the gateway were queried first, this route would be an oracle for
        // arbitrary order ids/session ids using our secret credentials.
        $this->paypalMock()->shouldReceive('showOrder')->never();

        $this->getJson('/api/v1/payment/paypal/status/FORGED_REFERENCE')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This payment method is not available.');
    }

    public function test_status_endpoint_reads_a_pending_order_without_creating_a_donation(): void
    {
        PaymentPendingOrder::create([
            'payment_method' => 'paypal',
            'gateway_reference' => 'PENDING_REF_1',
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        $this->paypalMock()->shouldReceive('showOrder')->once()->andReturn([
            'id' => 'PENDING_REF_1',
            'status' => 'CREATED',
        ]);

        $this->getJson('/api/v1/payment/paypal/status/PENDING_REF_1')
            ->assertOk()
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.payment_method', 'paypal');

        $this->assertSame(0, Donation::count());
    }

    public function test_status_endpoint_reports_a_completed_donation_from_our_own_records(): void
    {
        Donation::create([
            'payment_method' => 'paypal',
            'gateway_reference' => 'DONE_REF_1',
            'amount' => 40.00,
            'currency' => 'USD',
            'status' => 'completed',
        ]);

        // A recorded donation is final: no gateway round-trip, so a provider
        // outage can never turn a confirmed payment into "unknown".
        $this->paypalMock()->shouldReceive('showOrder')->never();

        $this->getJson('/api/v1/payment/paypal/status/DONE_REF_1')
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.amount', 40);
    }

    public function test_status_endpoint_reports_a_declined_checkout_instead_of_a_404(): void
    {
        PaymentPendingOrder::create([
            'payment_method' => 'paypal',
            'gateway_reference' => 'DECLINED_REF_1',
            'amount' => 25.00,
            'currency' => 'USD',
            'status' => PaymentPendingOrder::STATUS_FAILED,
            'status_reason' => 'INSTRUMENT_DECLINED',
        ]);

        // Closed checkouts are answered from our records; asking the gateway
        // again could 404 for a voided order and defeat the whole point.
        $this->paypalMock()->shouldReceive('showOrder')->never();

        $this->getJson('/api/v1/payment/paypal/status/DECLINED_REF_1')
            ->assertOk()
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.reason', 'INSTRUMENT_DECLINED')
            ->assertJsonPath('data.amount', 25);
    }

    public function test_status_endpoint_reports_a_cancelled_checkout(): void
    {
        PaymentPendingOrder::create([
            'payment_method' => 'paypal',
            'gateway_reference' => 'CANCELLED_REF_1',
            'amount' => 10.00,
            'currency' => 'USD',
            'status' => PaymentPendingOrder::STATUS_CANCELLED,
        ]);

        $this->paypalMock()->shouldReceive('showOrder')->never();

        $this->getJson('/api/v1/payment/paypal/status/CANCELLED_REF_1')
            ->assertOk()
            ->assertJsonPath('data.state', 'cancelled')
            ->assertJsonPath('data.completed', false);
    }

    public function test_capture_on_a_closed_checkout_is_rejected_without_calling_the_gateway(): void
    {
        PaymentPendingOrder::create([
            'payment_method' => 'paypal',
            'gateway_reference' => 'CLOSED_REF_1',
            'amount' => 15.00,
            'currency' => 'USD',
            'status' => PaymentPendingOrder::STATUS_FAILED,
        ]);

        $this->paypalMock()->shouldReceive('captureOrder')->never();

        $this->postJson('/api/v1/payment/paypal/capture-order', [
            'order_id' => 'CLOSED_REF_1',
        ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Donation::count());
    }

    // ---------------------------------------------------------------------
    // MISSING CREDENTIALS — controlled 503, never a 500 with SDK detail
    // ---------------------------------------------------------------------

    public function test_stripe_checkout_without_a_secret_key_returns_generic_503(): void
    {
        PaymentMethod::query()->where('code', 'stripe')->update(['active' => true]);

        // Tests never import real credentials; make the state explicit.
        PaymentMethod::query()->where('code', 'stripe')->first()
            ->forceFill(['credentials' => null])->save();
        app(PaymentCredentials::class)->flush();

        $response = $this->postJson('/api/v1/payment/stripe/create-order', ['amount' => 40.00]);

        $response->assertStatus(503)->assertJsonPath('success', false);

        $body = json_encode($response->json());

        // No credential names, SDK text or provider class names may leak.
        foreach (['secret', 'credential', 'Stripe\\', 'PaymentProviderException'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_capture_for_a_hosted_checkout_method_is_422_not_a_500(): void
    {
        PaymentMethod::query()->where('code', 'stripe')->update(['active' => true]);

        $this->postJson('/api/v1/payment/stripe/capture-order', ['order_id' => 'cs_test_123'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This operation is not supported for this payment method.');
    }

    // ---------------------------------------------------------------------
    // ADMIN REGISTRY — admin only, non-secret
    // ---------------------------------------------------------------------

    public function test_admin_registry_requires_authentication_and_admin_role(): void
    {
        $this->getJson('/api/v1/admin/payment-methods')->assertStatus(401);

        $editor = User::factory()->create();
        $editor->assignRole(Role::firstOrCreate(['name' => 'Editor']));

        $this->withHeader('Authorization', 'Bearer '.$editor->createToken('test')->plainTextToken)
            ->getJson('/api/v1/admin/payment-methods')
            ->assertStatus(403);
    }

    public function test_admin_registry_includes_inactive_methods_but_never_credentials(): void
    {
        $response = $this->withHeaders($this->adminHeader())
            ->getJson('/api/v1/admin/payment-methods')
            ->assertOk();

        $stripe = collect($response->json('data.methods'))->firstWhere('code', 'stripe');

        $this->assertNotNull($stripe);
        $this->assertFalse($stripe['active']);
        $this->assertArrayNotHasKey('credentials', $stripe);
        $this->assertArrayNotHasKey('config', $stripe);
        $this->assertArrayNotHasKey('secret_key', $stripe);
    }

    // ---------------------------------------------------------------------
    // CONFIG AUDIT — changes attributable, secrets never recorded
    // ---------------------------------------------------------------------

    public function test_payment_method_config_changes_are_audited(): void
    {
        $method = PaymentMethod::query()->where('code', 'paypal')->firstOrFail();

        $method->update([
            'label' => 'PayPal (renamed)',
            'config' => array_merge($method->config ?? [], ['mode' => 'live']),
        ]);

        $log = TransactionLog::where('event', 'payment_method.updated')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('paypal', $log->meta['payment_method']);
        $this->assertSame('PayPal (renamed)', $log->after['label']);
        $this->assertSame('live', $log->after['config']['mode']);
        $this->assertSame('sandbox', $log->before['config']['mode']);
    }

    public function test_credential_rotation_is_not_written_to_the_audit_trail(): void
    {
        $method = PaymentMethod::query()->where('code', 'paypal')->firstOrFail();

        $before = TransactionLog::count();

        $method->forceFill([
            'credentials' => ['client_id' => 'id', 'client_secret' => 'super-secret-value'],
        ])->save();

        $this->assertSame($before, TransactionLog::count());

        // Belt and braces: the secret must not appear anywhere in the feed.
        $this->assertStringNotContainsString(
            'super-secret-value',
            json_encode(TransactionLog::all()->toArray())
        );
    }

    // ---------------------------------------------------------------------
    // PAYPAL APPROVAL LINK — payer-action is the sandbox flow today
    // ---------------------------------------------------------------------

    public function test_approval_url_is_extracted_from_a_payer_action_link(): void
    {
        $this->paypalMock()->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'PAYPAL_PAYER_ACTION',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.paypal.com/v2/checkout/orders/PAYPAL_PAYER_ACTION'],
                ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL_PAYER_ACTION'],
            ],
        ]);

        $result = app(PayPalProvider::class)->createCheckout([
            'amount' => 10,
            'currency' => 'USD',
        ]);

        $this->assertSame(
            'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL_PAYER_ACTION',
            $result['approval_url']
        );
    }
}
