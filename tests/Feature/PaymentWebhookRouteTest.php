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
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Webhook routing QA for the pluggable payment-method refactor.
 *
 * Two invariants:
 *  1. the legacy per-gateway route (/webhooks/paypal) and the new generic
 *     route (/webhooks/{method}) must both work during the transition window;
 *  2. the generic route is genuinely method-parameterised — an unknown method
 *     is a clean 404, and an inactive method's in-flight event still resolves
 *     and reaches that gateway's own signature verification (never a 404/500).
 */
class PaymentWebhookRouteTest extends TestCase
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
        $mock->shouldReceive('verifyWebhook')->andReturn(true);
        $mock->shouldReceive('showOrder')->andReturn([
            'id' => 'PAYPAL_ORDER_123',
            'status' => 'COMPLETED',
        ]);
        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    protected function paypalWebhookHeaders(): array
    {
        return [
            'paypal-auth-algo' => ['SHA256withRSA'],
            'paypal-cert-url' => ['https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123'],
            'paypal-transmission-id' => ['test-transmission-id'],
            'paypal-transmission-sig' => ['test-signature'],
            'paypal-transmission-time' => ['2026-08-11T10:00:00Z'],
        ];
    }

    protected function captureCompletedPayload(string $orderId = 'PAYPAL_ORDER_123'): array
    {
        return [
            'id' => 'WH_'.uniqid(),
            'event_type' => 'payment.capture.completed',
            'resource' => [
                'id' => 'CAPTURE_ABC',
                'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                'payer' => [
                    'email_address' => 'donor@example.com',
                    'name' => ['given_name' => 'Test', 'surname' => 'Donor'],
                ],
                'supplementary_data' => ['related_ids' => ['order_id' => $orderId]],
            ],
        ];
    }

    private function seedPendingOrder(): void
    {
        $user = User::factory()->create();
        $article = Article::create(['slug' => 'wh-article-'.uniqid(), 'title' => 'WH Article']);
        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'formula' => [['organization' => 'Wiki', 'percentage' => 100]],
        ]);

        PaymentPendingOrder::create([
            'payment_method' => 'paypal',
            'gateway_reference' => 'PAYPAL_ORDER_123',
            'user_id' => null,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Webhook Donor',
            'donor_email' => 'webhook@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
        ]);
    }

    private function postPayPalWebhook(string $path, array $payload): TestResponse
    {
        return $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );
    }

    public function test_legacy_paypal_alias_route_records_the_donation(): void
    {
        $this->mockPayPalClient();
        $this->seedPendingOrder();

        $this->postPayPalWebhook('/api/v1/webhooks/paypal', $this->captureCompletedPayload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $donation = Donation::where('payment_method', 'paypal')->where('gateway_reference', 'PAYPAL_ORDER_123')->first();
        $this->assertNotNull($donation);
        $this->assertSame('completed', $donation->status);
        // Phase 3: the generic pair is the donation's payment identity.
        $this->assertSame('paypal', $donation->payment_method);
        $this->assertSame('PAYPAL_ORDER_123', $donation->gateway_reference);
    }

    public function test_generic_route_dispatches_a_non_paypal_method_to_its_own_handler(): void
    {
        // /webhooks/stripe has no dedicated legacy route, so it exercises the
        // generic /webhooks/{method} pattern. Stripe's own signature check runs
        // (not PayPal's), so a bogus signature is rejected with 401 — proving
        // dispatch reached StripeWebhookController and did not 404.
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $response = $this->postJson(
            '/api/v1/webhooks/stripe',
            [],
            ['Stripe-Signature' => 't=1700000000,v1=bogus']
        );

        $response->assertStatus(401);
        $this->assertSame('Invalid signature', $response->json('message'));
    }

    public function test_unknown_method_is_rejected_without_a_500(): void
    {
        $response = $this->postJson('/api/v1/webhooks/does-not-exist', []);

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_donation_completed_log_carries_payment_method(): void
    {
        $this->mockPayPalClient();
        $this->seedPendingOrder();

        $this->postPayPalWebhook('/api/v1/webhooks/paypal', $this->captureCompletedPayload())
            ->assertOk();

        $log = TransactionLog::where('event', 'donation.completed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('paypal', $log->meta['payment_method']);
    }

    public function test_inactive_method_is_hidden_from_the_donor_registry_but_persisted(): void
    {
        $codes = array_column(
            $this->getJson('/api/v1/payment-methods')->json('data.methods'),
            'code'
        );

        $this->assertNotContains('stripe', $codes);
        $this->assertContains('paypal', $codes);
        $this->assertDatabaseHas('payment_methods', ['code' => 'stripe', 'active' => false]);
    }
}
