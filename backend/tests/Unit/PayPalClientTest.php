<?php

namespace Tests\Unit;

use App\Services\PayPalClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit tests for the PayPalClient service.
 */
class PayPalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paypal.client_id' => 'test-client-id',
            'services.paypal.client_secret' => 'test-client-secret',
            'services.paypal.mode' => 'sandbox',
            'services.paypal.webhook_id' => 'test-webhook-id',
        ]);
    }

    // -------------------------------------------------------------------------
    // getAccessToken
    // -------------------------------------------------------------------------

    public function test_get_access_token_returns_token(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $client = new PayPalClient();
        $token = $client->getAccessToken();

        $this->assertEquals('test-access-token', $token);
    }

    public function test_get_access_token_is_cached(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $client = new PayPalClient();

        // First call
        $token1 = $client->getAccessToken();
        // Second call — should NOT make another HTTP request
        $token2 = $client->getAccessToken();

        $this->assertEquals($token1, $token2);

        Http::assertSentCount(1);
    }

    public function test_get_access_token_uses_sandbox_url_in_sandbox_mode(): void
    {
        config(['services.paypal.mode' => 'sandbox']);

        Http::fake([
            'api-m.sandbox.paypal.com/*' => Http::response(['access_token' => 'token'], 200),
        ]);

        $client = new PayPalClient();
        $client->getAccessToken();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sandbox.paypal.com');
        });
    }

    public function test_get_access_token_uses_live_url_in_live_mode(): void
    {
        config(['services.paypal.mode' => 'live']);

        Http::fake([
            'api-m.paypal.com/*' => Http::response(['access_token' => 'token'], 200),
        ]);

        $client = new PayPalClient();
        $client->getAccessToken();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api-m.paypal.com')
                && ! str_contains($request->url(), 'sandbox');
        });
    }

    // -------------------------------------------------------------------------
    // createOrder
    // -------------------------------------------------------------------------

    public function test_create_order_sends_correct_payload(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER_123',
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/approve'],
                ],
            ], 201),
        ]);

        $client = new PayPalClient();
        $order = $client->createOrder(
            amount: 25.00,
            currency: 'USD',
            returnUrl: 'http://localhost:3000/payment/success',
            cancelUrl: 'http://localhost:3000/payment/cancel',
            metadata: ['invoice_id' => 'WD-TEST123']
        );

        $this->assertEquals('ORDER_123', $order['id']);
        $this->assertEquals('CREATED', $order['status']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer token')
                && $body['intent'] === 'CAPTURE'
                && $body['purchase_units'][0]['amount']['value'] === '25.00'
                && $body['purchase_units'][0]['amount']['currency_code'] === 'USD'
                && $body['purchase_units'][0]['invoice_id'] === 'WD-TEST123'
                && $body['payment_source']['paypal']['experience_context']['return_url']
                    === 'http://localhost:3000/payment/success';
        });
    }

    public function test_create_order_uses_correct_endpoint(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/*' => Http::response(['id' => 'ORDER_123', 'status' => 'CREATED', 'links' => []], 201),
        ]);

        $client = new PayPalClient();
        $client->createOrder(10.00, 'EUR', '', '');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/checkout/orders');
        });
    }

    // -------------------------------------------------------------------------
    // captureOrder
    // -------------------------------------------------------------------------

    public function test_capture_order_sends_correct_endpoint(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_123/capture' => Http::response([
                'id' => 'ORDER_123',
                'status' => 'COMPLETED',
            ], 201),
        ]);

        $client = new PayPalClient();
        $result = $client->captureOrder('ORDER_123');

        $this->assertEquals('COMPLETED', $result['status']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/checkout/orders/ORDER_123/capture')
                && $request->method() === 'POST';
        });
    }

    // -------------------------------------------------------------------------
    // showOrder
    // -------------------------------------------------------------------------

    public function test_show_order_uses_get(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER_123' => Http::response([
                'id' => 'ORDER_123',
                'status' => 'COMPLETED',
            ], 200),
        ]);

        $client = new PayPalClient();
        $order = $client->showOrder('ORDER_123');

        $this->assertEquals('ORDER_123', $order['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/checkout/orders/ORDER_123')
                && $request->method() === 'GET';
        });
    }

    // -------------------------------------------------------------------------
    // verifyWebhook
    // -------------------------------------------------------------------------

    public function test_verify_webhook_returns_false_when_no_webhook_id(): void
    {
        config(['services.paypal.webhook_id' => null]);

        $client = new PayPalClient();

        $result = $client->verifyWebhook([], '{}');

        $this->assertFalse($result);
    }

    public function test_verify_webhook_returns_true_when_payload_valid(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $client = new PayPalClient();

        $headers = [
            'paypal-auth-algo' => ['SHA256withRSA'],
            'paypal-cert-url' => ['https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123'],
            'paypal-transmission-id' => ['trans-123'],
            'paypal-transmission-sig' => ['sig-abc'],
            'paypal-transmission-time' => ['2026-08-11T10:00:00Z'],
        ];

        $body = json_encode([
            'id' => 'WH_123',
            'event_type' => 'payment.capture.completed',
            'resource' => ['id' => 'CAP_123'],
        ]);

        $result = $client->verifyWebhook($headers, $body);

        $this->assertTrue($result);
    }

    public function test_verify_webhook_returns_false_when_verification_fails(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600], 200),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $client = new PayPalClient();

        $result = $client->verifyWebhook(
            [
                'paypal-auth-algo' => ['SHA256withRSA'],
                'paypal-cert-url' => ['https://api-m.sandbox.paypal.com/v1/notifications/certs/CERT-123'],
                'paypal-transmission-id' => ['trans-123'],
                'paypal-transmission-sig' => ['sig-abc'],
                'paypal-transmission-time' => ['2026-08-11T10:00:00Z'],
            ],
            json_encode(['id' => 'WH_123', 'event_type' => 'test'])
        );

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // extractCaptureData
    // -------------------------------------------------------------------------

    public function test_extract_capture_data_extracts_all_fields(): void
    {
        $payload = [
            'id' => 'ORDER_123',
            'status' => 'COMPLETED',
            'create_time' => '2026-08-11T10:00:00Z',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'CAP_ABC',
                        'amount' => [
                            'currency_code' => 'USD',
                            'value' => '50.00',
                        ],
                        'create_time' => '2026-08-11T10:00:00Z',
                    ]],
                ],
            ]],
            'payer' => [
                'payer_id' => 'PAYER_XYZ',
                'email_address' => 'test@example.com',
                'name' => [
                    'given_name' => 'John',
                    'surname' => 'Doe',
                ],
            ],
        ];

        $data = PayPalClient::extractCaptureData($payload);

        $this->assertEquals(50.00, $data['amount']);
        $this->assertEquals('USD', $data['currency']);
        $this->assertEquals('PAYER_XYZ', $data['payer_id']);
        $this->assertEquals('test@example.com', $data['payer_email']);
        $this->assertEquals('John Doe', $data['payer_name']);
        $this->assertEquals('CAP_ABC', $data['payment_id']);
        $this->assertEquals('COMPLETED', $data['order_status']);
    }

    public function test_extract_capture_data_handles_missing_fields_gracefully(): void
    {
        $payload = [
            'id' => 'ORDER_123',
            'status' => 'UNKNOWN',
        ];

        $data = PayPalClient::extractCaptureData($payload);

        $this->assertEquals(0.0, $data['amount']);
        $this->assertEquals('USD', $data['currency']);
        $this->assertNull($data['payer_id']);
        $this->assertNull($data['payer_email']);
        $this->assertEquals('', $data['payer_name']);
        $this->assertNull($data['payment_id']);
        $this->assertEquals('UNKNOWN', $data['order_status']);
    }

    public function test_extract_capture_data_falls_back_to_purchase_unit_amount(): void
    {
        // No captures array — should fall back to purchase_unit amount
        $payload = [
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => '100.00',
                ],
            ]],
            'payer' => [
                'name' => ['given_name' => 'Jane', 'surname' => 'Smith'],
            ],
        ];

        $data = PayPalClient::extractCaptureData($payload);

        $this->assertEquals(100.00, $data['amount']);
        $this->assertEquals('EUR', $data['currency']);
        $this->assertEquals('Jane Smith', $data['payer_name']);
    }
}
