<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\PaymentPendingOrder;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Donor checkout QA for the pluggable payment-method refactor.
 *
 * The donor flow must remain PayPal-only while Stripe is `active = 0`, and the
 * resulting donation must be readable through the generic
 * `payment_method` / `gateway_reference` pair — with the legacy PayPal order id
 * still mirrored so pre-refactor code and the phase-3 rollback keep working.
 */
class PaymentCheckoutQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function mockPayPalClient(): MockInterface
    {
        $mock = Mockery::mock(PayPalClient::class);
        $mock->shouldReceive('getAccessToken')->andReturn('test-access-token');
        $mock->shouldReceive('createOrder')->andReturn([
            'id' => 'PAYPAL_ORDER_QA',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=QA'],
            ],
        ]);
        $mock->shouldReceive('captureOrder')->andReturn([
            'id' => 'PAYPAL_ORDER_QA',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE_QA',
                        'amount' => ['currency_code' => 'USD', 'value' => '40.00'],
                        'create_time' => '2026-10-19T10:00:00Z',
                    ]],
                ],
            ]],
            'payer' => [
                'payer_id' => 'PAYER_QA',
                'email_address' => 'qa-donor@example.com',
                'name' => ['given_name' => 'QA', 'surname' => 'Donor'],
            ],
        ]);

        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    private function makeFormula(User $user): DonationFormula
    {
        $article = Article::create(['slug' => 'qa-article-'.uniqid(), 'title' => 'QA Article']);

        return DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'formula' => [['organization' => 'QA Org', 'percentage' => 100]],
        ]);
    }

    public function test_donor_only_sees_active_income_methods(): void
    {
        $data = $this->getJson('/api/v1/payment-methods')->assertOk()->json('data');

        $this->assertSame(['paypal'], array_column($data['methods'], 'code'));
        $this->assertSame(['paypal'], array_column($data['payout_methods'], 'code'));
    }

    public function test_paypal_checkout_writes_generic_payment_fields_and_audit_meta(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $formula = $this->makeFormula($user);

        $this->mockPayPalClient();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/create-order', [
                'amount' => 40.00,
                'currency' => 'USD',
                'donor_name' => 'QA Donor',
                'donor_email' => 'qa-donor@example.com',
                'formula_id' => $formula->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.order_id', 'PAYPAL_ORDER_QA');

        $this->assertDatabaseHas('payment_pending_orders', [
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL_ORDER_QA',
            'donation_formula_id' => $formula->id,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', ['order_id' => 'PAYPAL_ORDER_QA'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $donation = Donation::where('payment_method', 'paypal')->where('gateway_reference', 'PAYPAL_ORDER_QA')->firstOrFail();

        // The generic pair is the donation's payment identity (phase 3).
        $this->assertSame('paypal', $donation->payment_method);
        $this->assertSame('PAYPAL_ORDER_QA', $donation->gateway_reference);

        $this->assertNull(PaymentPendingOrder::where('payment_method', 'paypal')->where('gateway_reference', 'PAYPAL_ORDER_QA')->first());

        $log = TransactionLog::where('event', 'donation.completed')->latest('id')->first();
        $this->assertNotNull($log, 'capture must be audited');
        $this->assertSame('paypal', $log->meta['payment_method']);
    }

    public function test_capture_is_idempotent_and_does_not_duplicate_the_donation(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $formula = $this->makeFormula($user);

        $this->mockPayPalClient();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/create-order', [
                'amount' => 40.00,
                'formula_id' => $formula->id,
            ])->assertOk();

        $headers = ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($headers)
            ->postJson('/api/v1/paypal/capture-order', ['order_id' => 'PAYPAL_ORDER_QA'])
            ->assertOk();

        // Second capture: no pending row left, existing donation is returned.
        $this->withHeaders($headers)
            ->postJson('/api/v1/paypal/capture-order', ['order_id' => 'PAYPAL_ORDER_QA'])
            ->assertOk();

        $this->assertSame(1, Donation::where('payment_method', 'paypal')->where('gateway_reference', 'PAYPAL_ORDER_QA')->count());
    }

    /**
     * The SPA posts to the method-parameterised endpoints. They must reach the
     * same handler as the legacy /paypal/* aliases and echo the normalised
     * generic fields (`payment_method`, `reference`) so a caller never needs to
     * know which gateway answered.
     */
    public function test_generic_payment_endpoints_delegate_and_normalise(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $formula = $this->makeFormula($user);

        $this->mockPayPalClient();

        $headers = ['Authorization' => "Bearer {$token}"];

        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/payment/paypal/create-order', [
                'amount' => 40.00,
                'formula_id' => $formula->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'paypal')
            ->assertJsonPath('data.reference', 'PAYPAL_ORDER_QA')
            ->assertJsonPath('data.order_id', 'PAYPAL_ORDER_QA');

        $this->assertNotEmpty($created->json('data.approval_url'));

        $this->assertDatabaseHas('payment_pending_orders', [
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL_ORDER_QA',
        ]);

        $this->withHeaders($headers)
            ->postJson('/api/v1/payment/paypal/capture-order', ['order_id' => 'PAYPAL_ORDER_QA'])
            ->assertOk()
            ->assertJsonPath('data.payment_method', 'paypal')
            ->assertJsonPath('data.reference', 'PAYPAL_ORDER_QA');

        $this->assertSame(
            1,
            Donation::where('payment_method', 'paypal')->where('gateway_reference', 'PAYPAL_ORDER_QA')->count()
        );
    }

    public function test_generic_endpoint_rejects_inactive_and_unknown_methods(): void
    {
        $this->mockPayPalClient();

        // stripe is seeded active=0: not offered anew.
        $this->postJson('/api/v1/payment/stripe/create-order', ['amount' => 40.00])
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->postJson('/api/v1/payment/bkash/create-order', ['amount' => 40.00])
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
