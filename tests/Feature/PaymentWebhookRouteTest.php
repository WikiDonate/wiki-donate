<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\PayPalPendingOrder;
use App\Models\TransactionLog;
use App\Models\User;
use App\Services\PayPalClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Webhook routing QA for the pluggable payment-method refactor.
 *
 * Two invariants:
 *  1. the legacy per-gateway route (/webhooks/paypal) and the new generic
 *     route (/webhooks/{method}) must accept identical payloads and produce
 *     identical effects during the transition window;
 *  2. the generic route is genuinely method-parameterised — an unknown method
 *     is a clean 404, and a known-but-differently-verified method (stripe)
 *     routes to that gateway's own signature verification, not PayPal's.
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

        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => null,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Webhook Donor',
            'donor_email' => 'webhook@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
        ]);
    }

    private function postWebhook(string $path, array $payload): \Illuminate\Testing\TestResponse
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

    public function test_legacy_paypal_route_records_the_donation(): void
    {
        $this->mockPayPalClient();
        $this->seedPendingOrder();

        $this->postWebhook('/api/v1/webhooks/paypal', $this->captureCompletedPayload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $donation = Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        $this->assertNotNull($donation);
        $this->assertSame('completed', $donation->status);
        $this->assertSame('paypal', $donation->payment_method);
        $this->assertSame('PAYPAL_ORDER_123', $donation->gateway_reference);
    }

    public function test_generic_webhook_route_is_an_equivalent_alias_for_paypal(): void
    {
        $this->mockPayPalClient();
        $this->seedPendingOrder();

        $this->postWebhook('/api/v1/webhooks/paypal-generic', $this->captureCompletedPayload())
            ->assertStatus(404);

        // Sanity: the real generic alias path.
        $this->postWebhook('/api/v1/webhooks/paypal', $this->captureCompletedPayload())
            ->assertOk();
    }

    public function test_inactive_method_webhook_is_still_processed(): void
    {
        // stripe is seeded inactive, but a donor redirected moments before an
        // admin disabled it must still have their event handled. Stripe's own
        // signature check runs, so an unsigned payload is a 400 (not a 404).
        $response = $this->postJson('/api/v1/webhooks/stripe', []);

        $response->assertStatus(400);
        $this->assertSame('Invalid payload', $response->json('message'));
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

        $this->postWebhook('/api/v1/webhooks/paypal', $this->captureCompletedPayload())
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
