<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentMethod;
use Stripe\Stripe;

class StripeController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function addCard(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        $user = auth()->user();

        try {
            // Create customer if not exists
            if (! $user->customer_id) {
                $customer = Customer::create([
                    'name' => $user->username,
                ]);
                $user->customer_id = $customer->id;
            }

            // Retrieve payment method
            $paymentMethod = PaymentMethod::retrieve($request->payment_method_id);

            // Validate it's a card
            if ($paymentMethod->type !== 'card') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payment method',
                    'errors' => ['Only card payment methods are accepted'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Attach to customer if not already attached
            if (! $paymentMethod->customer || $paymentMethod->customer !== $user->customer_id) {
                $paymentMethod->attach(['customer' => $user->customer_id]);
            }

            // Update user's card_id
            $user->card_id = $paymentMethod->id;
            $user->save();

            // Set as default payment method
            Customer::update($user->customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethod->id,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Card saved successfully',
                'data' => $this->formatCardResponse($paymentMethod),
            ]);

        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe API error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Create a Stripe Checkout Session for one-time donations.
     */
    public function createCheckoutSession(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.50',
            'currency' => 'nullable|string|size:3',
            'success_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
            'donor_name' => 'nullable|string|max:255',
            'donor_email' => 'nullable|email|max:255',
        ]);

        try {
            $amount = $request->input('amount');
            $currency = $request->input('currency', 'usd');
            $userId = auth()->check() ? auth()->id() : null;

            $frontendUrl = config('app.url');
            $successUrl = $request->input('success_url', $frontendUrl.'/payment/success?session_id={CHECKOUT_SESSION_ID}');
            $cancelUrl = $request->input('cancel_url', $frontendUrl.'/payment/cancel');

            $lineItems = [[
                'price_data' => [
                    'currency' => $currency,
                    'product_data' => [
                        'name' => 'Donation to WikiDonate',
                    ],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]];

            // Build metadata — Stripe only accepts string values, so encode arrays as JSON
            $metadata = [
                'source' => 'wikidonate',
                'user_id' => $userId,
            ];

            if ($request->has('formula') && is_array($request->input('formula'))) {
                $metadata['formula'] = json_encode($request->input('formula'));
            }

            if ($request->filled('details')) {
                $metadata['details'] = $request->input('details');
            }

            $sessionConfig = [
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ];

            // Optionally pre-fill customer email
            if ($request->filled('donor_email')) {
                $sessionConfig['customer_email'] = $request->input('donor_email');
            } elseif ($userId) {
                $user = $request->user();
                if ($user->email) {
                    $sessionConfig['customer_email'] = $user->email;
                }
            }

            $session = Session::create($sessionConfig);

            return response()->json([
                'success' => true,
                'message' => 'Checkout session created',
                'data' => [
                    'checkout_url' => $session->url,
                    'session_id' => $session->id,
                ],
            ]);

        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe API error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function getCard()
    {
        $user = auth()->user();

        try {
            if (! $user->card_id) {
                return response()->json([
                    'success' => true,
                    'message' => 'No card found',
                    'data' => null,
                ]);
            }

            $paymentMethod = PaymentMethod::retrieve($user->card_id);

            return response()->json([
                'success' => true,
                'message' => 'Card retrieved successfully',
                'data' => $this->formatCardResponse($paymentMethod),
            ]);

        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe API errors',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Retrieve a Stripe Checkout Session by ID for the success page.
     */
    public function getCheckoutSession(string $sessionId)
    {
        try {
            $session = Session::retrieve($sessionId);

            // Session not found or invalid ID
            if (! $session || ! isset($session->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found',
                ], Response::HTTP_NOT_FOUND);
            }

            // Extract donor info
            $donorName = null;
            $donorEmail = null;

            if (! empty($session->customer_details)) {
                $donorName = $session->customer_details->name ?? null;
                $donorEmail = $session->customer_details->email ?? null;
            }

            if (! $donorEmail && ! empty($session->customer_email)) {
                $donorEmail = $session->customer_email;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'session_id' => $session->id,
                    'amount' => ($session->amount_total ?? 0) / 100,
                    'currency' => strtoupper($session->currency ?? 'usd'),
                    'payment_status' => $session->payment_status ?? 'unknown',
                    'status' => $session->status ?? 'unknown',
                    'donor_name' => $donorName,
                    'donor_email' => $donorEmail,
                    'mode' => $session->mode ?? 'payment',
                ],
            ]);

        } catch (ApiErrorException $e) {
            $statusCode = $e->getHttpStatus() === 404
                ? Response::HTTP_NOT_FOUND
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return response()->json([
                'success' => false,
                'message' => 'Session not found',
                'errors' => [$e->getMessage()],
            ], $statusCode);
        }
    }

    private function formatCardResponse(PaymentMethod $paymentMethod): array
    {
        $card = $paymentMethod->card;

        return [
            'card_id' => $paymentMethod->id,
            'brand' => $card->brand,
            'last4' => $card->last4,
            'exp_month' => $card->exp_month,
            'exp_year' => $card->exp_year,
            'customer_id' => $paymentMethod->customer,
        ];
    }
}
