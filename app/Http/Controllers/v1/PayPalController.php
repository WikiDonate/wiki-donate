<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\PayPalPendingOrder;
use App\Services\PayPalClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    public function createOrder(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.50|max:500000',
            'currency' => 'nullable|string|size:3',
            'donor_name' => 'nullable|string|max:255',
            'donor_email' => 'nullable|email|max:255',
            'formula' => 'nullable|array|max:100',
            'formula.*.organization' => 'nullable|string|max:255',
            'formula.*.percentage' => 'nullable|numeric|min:0|max:100',
            'details' => 'nullable|string|max:2000',
        ]);

        $amount = round((float) $request->input('amount'), 2);
        if ($amount < 0.50) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid amount',
                'errors' => ['Amount must be at least 0.50'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $currency = strtoupper($request->input('currency', 'USD'));
        $userId = auth()->check() ? auth()->id() : null;
        $frontendUrl = config('app.url');

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

        } catch (RequestException $e) {
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
    public function captureOrder(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|string',
        ]);

        $orderId = $request->input('order_id');

        try {
            // Wrap in a DB transaction with a row lock so concurrent capture
            // requests for the same order are serialized and the idempotency
            // check is reliable (no double-charge, no double-record).
            $result = DB::transaction(function () use ($orderId) {
                // Retrieve pending order metadata (locked)
                $pending = PayPalPendingOrder::where('paypal_order_id', $orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $pending) {
                    // No pending row — the order may already have been processed
                    // by the webhook or a previous capture. Check for an existing
                    // completed donation before failing.
                    $existing = Donation::where('paypal_order_id', $orderId)
                        ->where('status', 'completed')
                        ->first();

                    if ($existing) {
                        return $this->captureSuccessResponse($existing, true);
                    }

                    return [
                        'success' => false,
                        'status' => Response::HTTP_NOT_FOUND,
                        'message' => 'Pending order not found',
                        'errors' => ['Order not found or already processed'],
                    ];
                }

                // Idempotency: check if already completed
                $existing = Donation::where('paypal_order_id', $orderId)
                    ->where('status', 'completed')
                    ->first();

                if ($existing) {
                    return $this->captureSuccessResponse($existing, true);
                }

                // Capture the order with PayPal
                $captured = $this->paypal->captureOrder($orderId);
                $orderStatus = $captured['status'] ?? 'UNKNOWN';

                if ($orderStatus !== 'COMPLETED') {
                    Log::warning('PayPal order captured but not COMPLETED', [
                        'order_id' => $orderId,
                        'status' => $orderStatus,
                    ]);

                    return [
                        'success' => false,
                        'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                        'message' => "Order status: {$orderStatus}",
                        'errors' => ["Order status is {$orderStatus}, not completed"],
                    ];
                }

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

                Log::info('PayPal donation recorded', [
                    'donation_id' => $donation->id,
                    'paypal_order_id' => $orderId,
                    'amount' => $donation->amount,
                ]);

                return [
                    'success' => true,
                    'status' => Response::HTTP_OK,
                    'message' => 'Payment captured successfully',
                    'data' => [
                        'donation_id' => $donation->id,
                        'status' => 'completed',
                        'amount' => (float) $donation->amount,
                        'currency' => $donation->currency,
                    ],
                ];
            });

            if ($result instanceof JsonResponse) {
                return $result;
            }

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
            ] + (isset($result['data']) ? ['data' => $result['data']] : []), $result['status']);

        } catch (RequestException $e) {
            $body = $e->response->json() ?? [];

            // PayPal returns ORDER_ALREADY_CAPTURED when this capture races with
            // another one (or the order was already captured but our DB write
            // failed earlier). Recover gracefully instead of showing an error:
            // re-check for an existing donation, and if one exists, report success.
            $issues = collect($body['details'] ?? [])->pluck('issue')->map(fn ($i) => strtoupper($i));
            $alreadyCaptured = $issues->contains(fn ($i) => str_contains($i, 'ALREADY_CAPTURED'));

            if ($alreadyCaptured) {
                $existing = Donation::where('paypal_order_id', $orderId)
                    ->where('status', 'completed')
                    ->first();

                if ($existing) {
                    Log::info('PayPal capture race recovered — order already captured', [
                        'order_id' => $orderId,
                        'donation_id' => $existing->id,
                    ]);

                    return $this->captureSuccessResponse($existing, true);
                }

                // If no donation was recorded yet but PayPal says it was captured,
                // fetch the order and record it so the money isn't lost.
                try {
                    $order = $this->paypal->showOrder($orderId);
                    $captureData = PayPalClient::extractCaptureData($order);
                    $pending = PayPalPendingOrder::where('paypal_order_id', $orderId)->first();

                    $donation = Donation::create([
                        'paypal_order_id' => $orderId,
                        'user_id' => $pending?->user_id,
                        'donor_name' => $pending?->donor_name ?: $captureData['payer_name'],
                        'donor_email' => $pending?->donor_email ?: $captureData['payer_email'],
                        'amount' => $captureData['amount'],
                        'currency' => $captureData['currency'],
                        'status' => 'completed',
                        'metadata' => [
                            'payment_id' => $captureData['payment_id'],
                            'source' => 'paypal',
                            'formula' => $pending?->formula,
                            'details' => $pending?->details,
                        ],
                    ]);

                    $pending?->delete();
                    Cache::store('file')->forget('dashboard');

                    return $this->captureSuccessResponse($donation, true);
                } catch (\Throwable $recoveryError) {
                    Log::error('Could not recover already-captured order', [
                        'order_id' => $orderId,
                        'error' => $recoveryError->getMessage(),
                    ]);
                }
            }

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

    /**
     * Build the JSON response used when a donation is already completed.
     */
    protected function captureSuccessResponse(Donation $donation, bool $alreadyCaptured = false): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $alreadyCaptured ? 'Order already captured' : 'Payment captured successfully',
            'data' => [
                'donation_id' => $donation->id,
                'status' => $donation->status,
                'amount' => (float) $donation->amount,
                'currency' => $donation->currency,
            ],
        ]);
    }
}
