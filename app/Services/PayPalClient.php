<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalClient
{
    private string $clientId;

    private string $clientSecret;

    private string $baseUrl;

    private ?string $accessToken = null;

    private ?int $tokenExpiresAt = null;

    public function __construct()
    {
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->baseUrl = config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Get an OAuth 2.0 access token, cached until near-expiry.
     *
     * @throws RequestException
     */
    public function getAccessToken(): string
    {
        if ($this->accessToken && $this->tokenExpiresAt && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post("{$this->baseUrl}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

        $response->throw();

        $body = $response->json();
        $this->accessToken = $body['access_token'];
        // 60-second safety buffer under PayPal's expiry.
        $this->tokenExpiresAt = time() + (int) ($body['expires_in'] ?? 3600) - 60;

        Log::info('PayPal access token obtained', ['expires_in' => $body['expires_in'] ?? null]);

        return $this->accessToken;
    }

    /**
     * Create a PayPal order (CAPTURE intent).
     *
     * @param  string  $currency  ISO 4217 currency code
     * @param  array  $metadata  invoice_id, custom_id, request_id
     * @return array PayPal order payload
     *
     * @throws RequestException
     */
    public function createOrder(
        float $amount,
        string $currency = 'USD',
        string $returnUrl = '',
        string $cancelUrl = '',
        array $metadata = []
    ): array {
        $token = $this->getAccessToken();

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'description' => 'Donation to WikiDonate',
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'landing_page' => 'LOGIN',
                        'user_action' => 'PAY_NOW',
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                    ],
                ],
            ],
        ];

        if (! empty($metadata['invoice_id'])) {
            $payload['purchase_units'][0]['invoice_id'] = $metadata['invoice_id'];
        }

        if (! empty($metadata['custom_id'])) {
            $payload['purchase_units'][0]['custom_id'] = $metadata['custom_id'];
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'PayPal-Request-Id' => $metadata['request_id'] ?? uniqid('wikidonate_', true),
            ])
            ->post("{$this->baseUrl}/v2/checkout/orders", $payload);

        $response->throw();

        $order = $response->json();

        Log::info('PayPal order created', [
            'order_id' => $order['id'] ?? null,
            'status' => $order['status'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return $order;
    }

    /**
     * Capture an approved PayPal order.
     *
     *
     * @throws RequestException
     */
    public function captureOrder(string $orderId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->withBody('{}', 'application/json')
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture");

        $response->throw();

        $result = $response->json();

        Log::info('PayPal order captured', ['order_id' => $orderId, 'status' => $result['status'] ?? null]);

        return $result;
    }

    /**
     * Retrieve a PayPal order by ID.
     *
     *
     * @throws RequestException
     */
    public function showOrder(string $orderId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/v2/checkout/orders/{$orderId}");

        $response->throw();

        return $response->json();
    }

    /**
     * Create a batch payout — a real money transfer from the merchant
     * PayPal balance to each recipient's email via the Payouts API.
     *
     * Number of items is deliberately one per batch: each dashboard Pay
     * action maps to exactly one ledger row, and $senderBatchId (the payout
     * uuid) doubles as the PayPal-Request-Id for idempotency.
     *
     * @param  array  $items  [['recipient_email' => string, 'amount' => float,
     *                        'currency' => string, 'note' => string,
     *                        'sender_item_id' => string], ...]
     * @return array PayPal batch header + links payload
     *
     * @throws RequestException
     */
    public function createBatchPayout(array $items, string $senderBatchId, string $emailSubject = '', string $emailMessage = ''): array
    {
        $token = $this->getAccessToken();

        $payload = [
            'sender_batch_header' => [
                'sender_batch_id' => $senderBatchId,
            ],
            'items' => array_map(function (array $item, int $i) {
                return [
                    'recipient_type' => 'EMAIL',
                    'receiver' => $item['recipient_email'],
                    'amount' => [
                        'value' => number_format((float) $item['amount'], 2, '.', ''),
                        'currency' => strtoupper($item['currency']),
                    ],
                    'sender_item_id' => $item['sender_item_id'] ?? 'item_'.$i,
                    'note' => mb_substr($item['note'] ?? '', 0, 127), // PayPal caps notes at 127 chars
                ];
            }, $items, array_keys($items)),
        ];

        if ($emailSubject !== '') {
            $payload['sender_batch_header']['email_subject'] = mb_substr($emailSubject, 0, 255);
        }
        if ($emailMessage !== '') {
            $payload['sender_batch_header']['email_message'] = mb_substr($emailMessage, 0, 1000);
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'PayPal-Request-Id' => $senderBatchId,
            ])
            ->post("{$this->baseUrl}/v1/payments/payouts", $payload);

        $response->throw();

        $result = $response->json();

        Log::info('PayPal batch payout created', [
            'payout_batch_id' => $result['batch_header']['payout_batch_id'] ?? null,
            'batch_status' => $result['batch_header']['batch_status'] ?? null,
            'sender_batch_id' => $senderBatchId,
            'items' => count($items),
        ]);

        return $result;
    }

    /**
     * Retrieve a payout batch including per-item transaction statuses.
     *
     * @throws RequestException
     */
    public function showBatchPayout(string $payoutBatchId): array
    {
        $token = $this->getAccessToken();

        $response = Http::withToken($token)
            ->get("{$this->baseUrl}/v1/payments/payouts/{$payoutBatchId}", ['fields' => 'items']);

        $response->throw();

        return $response->json();
    }

    /**
     * Flatten a batch payload's per-item statuses, keyed by sender_item_id
     * (which we set to the payout uuid when creating the batch).
     */
    public static function extractBatchItemStatuses(array $batch): array
    {
        $statuses = [];

        foreach ($batch['items'] ?? [] as $item) {
            $senderItemId = $item['sender_item_id'] ?? null;
            if ($senderItemId === null) {
                continue;
            }

            $error = $item['errors']['message'] ?? null
                ?: ($item['errors']['description'] ?? null);

            $statuses[$senderItemId] = [
                'status' => $item['transaction_status'] ?? ($item['payout_item_status'] ?? 'PENDING'),
                'payout_item_id' => $item['payout_item_id'] ?? null,
                'error' => $error,
            ];
        }

        return $statuses;
    }

    /**
     * Verify the signature of an incoming PayPal webhook.
     *
     * @param  array  $headers  Lowercase-keyed request headers
     * @param  string  $body  Raw request body
     */
    public function verifyWebhook(array $headers, string $body): bool
    {
        $webhookId = config('services.paypal.webhook_id');

        if (! $webhookId) {
            Log::error('PayPal webhook ID not configured — skipping verification');

            return false;
        }

        try {
            $token = $this->getAccessToken();

            $payload = [
                'auth_algo' => $headers['paypal-auth-algo'][0] ?? '',
                'cert_url' => $headers['paypal-cert-url'][0] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'][0] ?? '',
                'transmission_sig' => $headers['paypal-transmission-sig'][0] ?? '',
                'transmission_time' => $headers['paypal-transmission-time'][0] ?? '',
                'webhook_id' => $webhookId,
                'webhook_event' => json_decode($body, true),
            ];

            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", $payload);

            $response->throw();

            $verified = ($response->json('verification_status') ?? '') === 'SUCCESS';

            if (! $verified) {
                Log::warning('PayPal webhook verification failed', [
                    'status' => $response->json('verification_status'),
                ]);
            }

            return $verified;

        } catch (\Throwable $e) {
            Log::error('PayPal webhook verification error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Extract key fields from a captured PayPal order payload.
     */
    public static function extractCaptureData(array $capturedOrder): array
    {
        $purchaseUnit = $capturedOrder['purchase_units'][0] ?? [];
        $captures = $purchaseUnit['payments']['captures'] ?? [];
        $firstCapture = $captures[0] ?? [];
        $payer = $capturedOrder['payer'] ?? [];
        $name = $payer['name'] ?? [];
        $amountInfo = $firstCapture['amount'] ?? $purchaseUnit['amount'] ?? [];

        return [
            'amount' => (float) ($amountInfo['value'] ?? 0),
            'currency' => $amountInfo['currency_code'] ?? 'USD',
            'payer_id' => $payer['payer_id'] ?? null,
            'payer_email' => $payer['email_address'] ?? null,
            'payer_name' => trim(($name['given_name'] ?? '').' '.($name['surname'] ?? '')),
            'payment_id' => $firstCapture['id'] ?? null,
            'order_status' => $capturedOrder['status'] ?? 'UNKNOWN',
            'create_time' => $firstCapture['create_time'] ?? $capturedOrder['create_time'] ?? null,
        ];
    }
}
