<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\PayPalPendingOrder;
use App\Services\PayPalClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PayPalController extends Controller
{
    private PayPalClient $paypal;

    public function __construct(PayPalClient $paypal)
    {
        $this->paypal = $paypal;
    }

    /**
     * Create a PayPal order and store donor/formula payload in pending orders.
     *
     * POST /paypal/create-order
     */
    public function createOrder(Request $request): Response
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.50',
            'currency' => 'nullable|string|size:3',
            'donor_name' => 'nullable|string|max:255',
            'donor_email' => 'nullable|email|max:255',
        ]);

        $amount = (float) $request->input('amount');
        $currency = strtoupper($request->input('currency', 'USD'));
        $userId = auth()->check() ? auth()->id() : null;
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        $returnUrl = $frontendUrl.'/payment/success';
        $cancelUrl = $frontendUrl.'/payment/cancel';

        try {
            $invoiceId = 'WD-'.strtoupper(uniqid());

            $order = $this->paypal->createOrder(
                $amount,
                $currency,
                $returnUrl,
                $cancelUrl,
                ['invoice_id' => $invoiceId]
            );

            $orderId = $order['id'] ?? null;

            if (! $orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create PayPal order',
                    'errors' => ['No order ID returned'],
                ], Response::HTTP_BAD_REQUEST);
            }

            // Store full donor/formula payload keyed by PayPal order ID.
            // custom_id is limited to 127 chars so we use a separate table instead.
            PayPalPendingOrder::create([
                'paypal_order_id' => $orderId,
                'user_id' => $userId,
                'donor_email' => $request->input('donor_email'),
                'donor_name' => $request->input('donor_name'),
                'amount' => $amount,
                'currency' => $currency,
                'formula' => $request->input('formula'),
                'details' => $request->input('details'),
            ]);

            // Find the approval URL from the order links
            $approvalLink = null;
            foreach (($order['links'] ?? []) as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approvalLink = $link['href'];
                    break;
                }
            }

            Log::info('PayPal order created via controller', [
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => $currency,
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'PayPal order created',
                'data' => [
                    'order_id' => $orderId,
                    'approval_url' => $approvalLink,
                ],
            ]);

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json() ?? [];
            Log::error('PayPal API error creating order', [
                'message' => $e->getMessage(),
                'details' => $body,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'PayPal API error',
                'errors' => [$body['message'] ?? $e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);

        } catch (\Throwable $e) {
            Log::error('PayPal order creation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create PayPal order',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Capture a PayPal order after buyer approval.
     *
     * POST /paypal/capture-order
     */
    public function captureOrder(Request $request): Response
    {
        $request->validate([
            'order_id' => 'required|string',
        ]);

        $orderId = $request->input('order_id');

        try {
            // Retrieve pending order metadata
            $pending = PayPalPendingOrder::where('paypal_order_id', $orderId)->first();

            if (! $pending) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pending order not found',
                    'errors' => ['Order not found or already processed'],
                ], Response::HTTP_NOT_FOUND);
            }

            // Idempotency: check if already completed
            $existing = Donation::where('paypal_order_id', $orderId)
                ->where('status', 'completed')
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Order already captured',
                    'data' => [
                        'donation_id' => $existing->id,
                        'status' => $existing->status,
                    ],
                ]);
            }

            // Capture the order with PayPal
            $captured = $this->paypal->captureOrder($orderId);
            $orderStatus = $captured['status'] ?? 'UNKNOWN';

            if ($orderStatus === 'COMPLETED') {
                $captureData = PayPalClient::extractCaptureData($captured);

                $donation = Donation::create([
                    'paypal_order_id' => $orderId,
                    'user_id' => $pending->user_id,
                    'donor_name' => $pending->donor_name ?: $captureData['payer_name'],
                    'donor_email' => $pending->donor_email ?: $captureData['payer_email'],
                    'amount' => $captureData['amount'],
                    'currency' => $captureData['currency'],
                    'status' => 'completed',
                    'metadata' => [
                        'payment_id' => $captureData['payment_id'],
                        'payer_id' => $captureData['payer_id'],
                        'source' => 'paypal',
                        'formula' => $pending->formula,
                        'details' => $pending->details,
                    ],
                ]);

                $pending->delete();

                Cache::store('file')->forget('dashboard');

                Log::info('PayPal donation recorded', [
                    'donation_id' => $donation->id,
                    'paypal_order_id' => $orderId,
                    'amount' => $donation->amount,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Payment captured successfully',
                    'data' => [
                        'donation_id' => $donation->id,
                        'status' => 'completed',
                    ],
                ]);
            }

            Log::warning('PayPal order captured but not COMPLETED', [
                'order_id' => $orderId,
                'status' => $orderStatus,
            ]);

            return response()->json([
                'success' => false,
                'message' => "Order status: {$orderStatus}",
                'errors' => ["Order status is {$orderStatus}, not completed"],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

        } catch (\Illuminate\Http\Client\RequestException $e) {
            $body = $e->response->json() ?? [];
            Log::error('PayPal capture API error', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'details' => $body,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'PayPal capture failed',
                'errors' => [$body['message'] ?? $e->getMessage()],
            ], Response::HTTP_BAD_REQUEST);

        } catch (\Throwable $e) {
            Log::error('PayPal capture error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to capture PayPal order',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
