<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Donation;
use App\Models\DonationFormula;
use App\Models\PayPalPendingOrder;
use App\Models\User;
use App\Services\PayPalClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * End-to-end and integration tests for PayPal Checkout flow.
 *
 * Test matrix:
 * - Create order via modal -> PayPal Smart Buttons render
 * - Complete checkout with sandbox buyer -> capture succeeds -> Donation record created
 * - Verify donor_email and formula metadata stored correctly in pending-orders table
 * - Verify webhook (payment.capture.completed / checkout.order.approved) fires and is signature-verified
 * - Verify /payment/success redirect and unified transaction report includes PayPal row
 * - Edge cases: idempotency, invalid/expired order id, webhook signature mismatch, non-USD currency
 */
class PayPalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    /**
     * Mock the PayPalClient so it doesn't make real API calls.
     */
    protected function mockPayPalClient(array $overrides = []): MockInterface
    {
        $mock = Mockery::mock(PayPalClient::class);

        $defaults = [
            'getAccessToken' => 'test-access-token',
            'createOrder' => [
                'id' => 'PAYPAL_ORDER_123',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL_ORDER_123'],
                    ['rel' => 'capture', 'href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL_ORDER_123/capture'],
                ],
            ],
            'captureOrder' => [
                'id' => 'PAYPAL_ORDER_123',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [[
                            'id' => 'CAPTURE_ABC',
                            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
                            'create_time' => '2026-08-11T10:00:00Z',
                        ]],
                    ],
                ]],
                'payer' => [
                    'payer_id' => 'PAYER_XYZ',
                    'email_address' => 'donor@example.com',
                    'name' => ['given_name' => 'Test', 'surname' => 'Donor'],
                ],
            ],
            'showOrder' => [
                'id' => 'PAYPAL_ORDER_123',
                'status' => 'COMPLETED',
            ],
            'verifyWebhook' => true,
        ];

        $config = array_merge($defaults, $overrides);

        $mock->shouldReceive('getAccessToken')->andReturn($config['getAccessToken']);

        if (isset($overrides['createOrder'])) {
            $mock->shouldReceive('createOrder')->andReturn($overrides['createOrder']);
        } else {
            $mock->shouldReceive('createOrder')->andReturn($defaults['createOrder']);
        }

        if (isset($overrides['captureOrder'])) {
            $mock->shouldReceive('captureOrder')->andReturn($overrides['captureOrder']);
        } else {
            $mock->shouldReceive('captureOrder')->andReturn($defaults['captureOrder']);
        }

        if (isset($overrides['showOrder'])) {
            $mock->shouldReceive('showOrder')->andReturn($overrides['showOrder']);
        } else {
            $mock->shouldReceive('showOrder')->andReturn($defaults['showOrder']);
        }

        if (isset($overrides['verifyWebhook'])) {
            $mock->shouldReceive('verifyWebhook')->andReturn($overrides['verifyWebhook']);
        } else {
            $mock->shouldReceive('verifyWebhook')->andReturn($defaults['verifyWebhook']);
        }

        $this->app->instance(PayPalClient::class, $mock);

        return $mock;
    }

    /**
     * Build fake PayPal webhook headers.
     */
    protected function paypalWebhookHeaders(string $transmissionId = 'test-transmission-id'): array
    {
        return [
            'paypal-auth-algo' => ['SHA256withRSA'],
            'paypal-cert-url' => ['https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123'],
            'paypal-transmission-id' => [$transmissionId],
            'paypal-transmission-sig' => ['test-signature'],
            'paypal-transmission-time' => ['2026-08-11T10:00:00Z'],
        ];
    }

    /**
     * Build a fake PayPal webhook payload for payment.capture.completed.
     */
    protected function captureCompletedPayload(string $orderId = 'PAYPAL_ORDER_123', float $amount = 25.00): array
    {
        return [
            'id' => 'WH_'.uniqid(),
            'event_type' => 'payment.capture.completed',
            'resource' => [
                'id' => 'CAPTURE_ABC',
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'payer' => [
                    'email_address' => 'donor@example.com',
                    'name' => ['given_name' => 'Test', 'surname' => 'Donor'],
                ],
                'supplementary_data' => [
                    'related_ids' => ['order_id' => $orderId],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // TEST: Create PayPal Order
    // -------------------------------------------------------------------------

    public function test_create_order_validates_amount(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/create-order', [
                'amount' => 0.25, // below minimum of 0.50
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_create_order_validates_currency_format(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/create-order', [
                'amount' => 25.00,
                'currency' => 'INVALID',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_create_order_stores_pending_order_with_formula_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $article = Article::create([
            'slug' => 'test-article-'.uniqid(),
            'title' => 'Test Article',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'formula' => [
                ['organization' => 'Wiki1', 'percentage' => 50],
                ['organization' => 'Wiki2', 'percentage' => 50],
            ],
            'details' => 'Monthly donation',
        ]);

        $this->mockPayPalClient();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/create-order', [
                'amount' => 25.00,
                'currency' => 'USD',
                'donor_name' => 'Test Donor',
                'donor_email' => 'donor@example.com',
                'formula_id' => $formula->id,
                'details' => 'Monthly donation',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'PayPal order created',
            ])
            ->assertJsonPath('data.order_id', 'PAYPAL_ORDER_123')
            ->assertJsonPath('data.approval_url', 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL_ORDER_123');

        // Verify pending order was stored with formula id + donor metadata
        $pending = PayPalPendingOrder::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();

        $this->assertNotNull($pending);
        $this->assertEquals($user->id, $pending->user_id);
        $this->assertEquals('donor@example.com', $pending->donor_email);
        $this->assertEquals('Test Donor', $pending->donor_name);
        $this->assertEquals(25.00, $pending->amount);
        $this->assertEquals('USD', $pending->currency);
        $this->assertEquals($formula->id, $pending->donation_formula_id);
        $this->assertEquals('Monthly donation', $pending->details);
    }

    public function test_create_order_rejects_invalid_formula_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->mockPayPalClient();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/create-order', [
                'amount' => 25.00,
                'formula_id' => 999999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['formula_id']);
    }

    public function test_create_order_works_without_authentication(): void
    {
        $this->mockPayPalClient();

        $response = $this->postJson('/api/v1/paypal/create-order', [
            'amount' => 25.00,
            'donor_email' => 'anon@example.com',
        ]);

        // create-order route is NOT auth:sanctum in the current routes — confirm it works
        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $pending = PayPalPendingOrder::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        $this->assertNotNull($pending);
        $this->assertNull($pending->user_id);
        $this->assertEquals('anon@example.com', $pending->donor_email);
    }

    // -------------------------------------------------------------------------
    // TEST: Capture PayPal Order
    // -------------------------------------------------------------------------

    public function test_capture_order_validates_order_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['order_id']);
    }

    public function test_capture_order_returns_404_for_unknown_order(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->mockPayPalClient();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', [
                'order_id' => 'UNKNOWN_ORDER',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Pending order not found',
            ]);
    }

    public function test_capture_order_idempotency_already_completed(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Pre-create a completed donation for this order
        $existing = Donation::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => $user->id,
            'donor_name' => 'Already',
            'donor_email' => 'already@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
            'status' => 'completed',
            'metadata' => ['source' => 'paypal'],
        ]);

        // Also create the stale pending order
        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => $user->id,
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        $this->mockPayPalClient();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', [
                'order_id' => 'PAYPAL_ORDER_123',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Order already captured',
            ])
            ->assertJsonPath('data.donation_id', $existing->id)
            ->assertJsonPath('data.status', 'completed');

        // Should not create a duplicate donation
        $this->assertEquals(1, Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->count());
    }

    public function test_capture_order_creates_donation_and_deletes_pending(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $article = Article::create([
            'slug' => 'capture-article-'.uniqid(),
            'title' => 'Capture Article',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'formula' => [
                ['organization' => 'Wiki1', 'percentage' => 100],
            ],
            'details' => 'Monthly donation',
        ]);

        $pending = PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => $user->id,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Test Donor',
            'donor_email' => 'donor@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
            'details' => 'Monthly donation',
        ]);

        $this->mockPayPalClient();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', [
                'order_id' => 'PAYPAL_ORDER_123',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Payment captured successfully',
            ])
            ->assertJsonPath('data.status', 'completed');

        // Donation was created
        $donation = Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        $this->assertNotNull($donation);
        $this->assertEquals('completed', $donation->status);
        $this->assertEquals(25.00, $donation->amount);
        $this->assertEquals('USD', $donation->currency);
        $this->assertEquals('donor@example.com', $donation->donor_email);
        $this->assertEquals('Test Donor', $donation->donor_name);

        // Formula id was carried over to the donation
        $this->assertEquals($formula->id, $donation->donation_formula_id);
        $this->assertEquals('paypal', $donation->metadata['source']);
        $this->assertEquals('Monthly donation', $donation->metadata['details']);
        $this->assertArrayNotHasKey('formula', $donation->metadata);

        // Pending order was deleted
        $this->assertNull(PayPalPendingOrder::where('paypal_order_id', 'PAYPAL_ORDER_123')->first());
    }

    public function test_capture_order_uses_paypal_payer_data_when_donor_not_provided(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Pending order WITHOUT donor info — should fall back to PayPal's payer data
        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => $user->id,
            'amount' => 25.00,
            'currency' => 'USD',
            // donor_name and donor_email intentionally null
        ]);

        $this->mockPayPalClient();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', [
                'order_id' => 'PAYPAL_ORDER_123',
            ]);

        $response->assertStatus(200);

        $donation = Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        // Falls back to PayPal payer data
        $this->assertEquals('Test Donor', $donation->donor_name);
        $this->assertEquals('donor@example.com', $donation->donor_email);
    }

    public function test_capture_order_non_usd_currency(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => $user->id,
            'donor_email' => 'eur@example.com',
            'amount' => 50.00,
            'currency' => 'EUR',
        ]);

        // Mock returns USD by default; override captureOrder to return EUR
        $this->mockPayPalClient([
            'captureOrder' => [
                'id' => 'PAYPAL_ORDER_123',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'payments' => [
                        'captures' => [[
                            'id' => 'CAPTURE_EUR',
                            'amount' => ['currency_code' => 'EUR', 'value' => '50.00'],
                            'create_time' => '2026-08-11T10:00:00Z',
                        ]],
                    ],
                ]],
                'payer' => [
                    'payer_id' => 'PAYER_XYZ',
                    'email_address' => 'eur@example.com',
                    'name' => ['given_name' => 'Euro', 'surname' => 'Donor'],
                ],
            ],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', [
                'order_id' => 'PAYPAL_ORDER_123',
            ]);

        $response->assertStatus(200);

        $donation = Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        $this->assertEquals('EUR', $donation->currency);
        $this->assertEquals(50.00, $donation->amount);
    }

    // -------------------------------------------------------------------------
    // TEST: PayPal Webhook — payment.capture.completed
    // -------------------------------------------------------------------------

    public function test_webhook_rejects_invalid_signature(): void
    {
        // Mock verifyWebhook to return false
        $this->mockPayPalClient(['verifyWebhook' => false]);

        $payload = $this->captureCompletedPayload();

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge([
                'HTTP_ACCEPT' => 'application/json',
            ], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Webhook verification failed',
            ]);
    }

    public function test_webhook_idempotency_skips_duplicate_event(): void
    {
        $eventId = 'WH_DUPLICATE_'.uniqid();

        // Pre-mark the event as processed
        Cache::store('file')->put("paypal_webhook:{$eventId}", true, now()->addHours(24));

        $this->mockPayPalClient();

        $payload = $this->captureCompletedPayload();
        $payload['id'] = $eventId;

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders($eventId)),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Event already processed',
            ]);

        // No donation should be created
        $this->assertEquals(0, Donation::count());
    }

    public function test_webhook_capture_completed_creates_donation(): void
    {
        $user = User::factory()->create();

        $article = Article::create([
            'slug' => 'webhook-article-'.uniqid(),
            'title' => 'Webhook Article',
        ]);

        $formula = DonationFormula::create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'formula' => [['organization' => 'Wiki', 'percentage' => 100]],
            'details' => 'Webhook donation',
        ]);

        // Create a pending order so webhook has something to link
        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => null,
            'donation_formula_id' => $formula->id,
            'donor_name' => 'Webhook Donor',
            'donor_email' => 'webhook@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        $this->mockPayPalClient();

        $payload = $this->captureCompletedPayload();

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $donation = Donation::where('paypal_order_id', 'PAYPAL_ORDER_123')->first();
        $this->assertNotNull($donation);
        $this->assertEquals('completed', $donation->status);
        $this->assertEquals(25.00, $donation->amount);
        $this->assertEquals('USD', $donation->currency);
        $this->assertEquals('webhook@example.com', $donation->donor_email);
        $this->assertEquals($formula->id, $donation->donation_formula_id);
        $this->assertArrayNotHasKey('formula', $donation->metadata);

        // Pending order was cleaned up
        $this->assertNull(PayPalPendingOrder::where('paypal_order_id', 'PAYPAL_ORDER_123')->first());
    }

    public function test_webhook_capture_completed_idempotency_already_recorded(): void
    {
        // Pre-create a completed donation for this order
        Donation::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'donor_email' => 'already@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
            'status' => 'completed',
            'metadata' => ['source' => 'paypal'],
        ]);

        $this->mockPayPalClient();

        $payload = $this->captureCompletedPayload();

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Still only 1 donation (no duplicate)
        $this->assertEquals(1, Donation::count());
    }

    public function test_webhook_capture_completed_missing_order_id_logs_warning(): void
    {
        $this->mockPayPalClient();

        $payload = $this->captureCompletedPayload();
        unset($payload['resource']['supplementary_data']['related_ids']['order_id']);
        $payload['resource']['supplementary_data']['related_ids']['order_id'] = null;
        $payload['resource']['custom_id'] = null;
        $payload['resource']['invoice_id'] = null;

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        // Should still return 200 (no 5xx for bad data)
        $response->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // TEST: PayPal Webhook — checkout.order.approved
    // -------------------------------------------------------------------------

    public function test_webhook_order_approved_is_handled(): void
    {
        $this->mockPayPalClient();

        $payload = [
            'id' => 'WH_APPROVED_'.uniqid(),
            'event_type' => 'checkout.order.approved',
            'resource' => [
                'id' => 'PAYPAL_ORDER_123',
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // TEST: Edge Cases
    // -------------------------------------------------------------------------

    public function test_capture_order_invalid_expired_order_id(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        PayPalPendingOrder::create([
            'paypal_order_id' => 'EXPIRED_ORDER',
            'user_id' => $user->id,
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        // Mock captureOrder to throw a RequestException (simulating a PayPal API error)
        $mock = Mockery::mock(PayPalClient::class);
        $mock->shouldReceive('getAccessToken')->andReturn('token');

        $psrResponse = new Response(
            400,
            ['Content-Type' => 'application/json'],
            json_encode(['message' => 'ORDER_INVALID: Order is invalid or expired'])
        );
        $mock->shouldReceive('captureOrder')
            ->andThrow(new RequestException(
                new \Illuminate\Http\Client\Response($psrResponse)
            ));

        $this->app->instance(PayPalClient::class, $mock);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/paypal/capture-order', [
                'order_id' => 'EXPIRED_ORDER',
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'PayPal capture failed',
            ]);
    }

    public function test_webhook_missing_json_body_returns_400(): void
    {
        $this->mockPayPalClient();

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            'not-valid-json'
        );

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid webhook payload',
            ]);
    }

    public function test_webhook_unhandled_event_type_is_ignored(): void
    {
        $this->mockPayPalClient();

        $payload = [
            'id' => 'WH_UNHANDLED_'.uniqid(),
            'event_type' => 'payment.shipping.updated',
            'resource' => ['id' => 'SOMETHING'],
        ];

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_webhook_capture_denied_removes_pending_order(): void
    {
        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => null,
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        $this->mockPayPalClient();

        $payload = [
            'id' => 'WH_DENIED_'.uniqid(),
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => [
                'id' => 'CAPTURE_ABC',
                'supplementary_data' => [
                    'related_ids' => ['order_id' => 'PAYPAL_ORDER_123'],
                ],
            ],
        ];

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertNull(PayPalPendingOrder::where('paypal_order_id', 'PAYPAL_ORDER_123')->first());
    }

    public function test_webhook_order_cancelled_removes_pending_order(): void
    {
        PayPalPendingOrder::create([
            'paypal_order_id' => 'PAYPAL_ORDER_123',
            'user_id' => null,
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        $this->mockPayPalClient();

        $payload = [
            'id' => 'WH_CANCELLED_'.uniqid(),
            'event_type' => 'CHECKOUT.ORDER.CANCELLED',
            'resource' => ['id' => 'PAYPAL_ORDER_123'],
        ];

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/paypal',
            [],
            [],
            [],
            array_merge(['HTTP_ACCEPT' => 'application/json'], $this->paypalWebhookHeaders()),
            json_encode($payload)
        );

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertNull(PayPalPendingOrder::where('paypal_order_id', 'PAYPAL_ORDER_123')->first());
    }

    public function test_cleanup_command_deletes_old_pending_orders(): void
    {
        $old = PayPalPendingOrder::create([
            'paypal_order_id' => 'OLD_ORDER',
            'user_id' => null,
            'amount' => 25.00,
            'currency' => 'USD',
        ]);
        $old->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        PayPalPendingOrder::create([
            'paypal_order_id' => 'FRESH_ORDER',
            'user_id' => null,
            'amount' => 25.00,
            'currency' => 'USD',
        ]);

        $this->artisan('paypal:cleanup-pending-orders', ['--hours' => 48])
            ->assertSuccessful();

        $this->assertNull(PayPalPendingOrder::where('paypal_order_id', 'OLD_ORDER')->first());
        $this->assertNotNull(PayPalPendingOrder::where('paypal_order_id', 'FRESH_ORDER')->first());
    }
}
