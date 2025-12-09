<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\Exception\ApiErrorException;
use App\Models\User;

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
            if (!$user->customer_id) {
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
            if (!$paymentMethod->customer || $paymentMethod->customer !== $user->customer_id) {
                $paymentMethod->attach(['customer' => $user->customer_id]);
            }

            // Update user's card_id
            $user->card_id = $paymentMethod->id;
            $user->save();

            // Set as default payment method
            Customer::update($user->customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethod->id
                ]
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Card saved successfully',
                'data' => $this->formatCardResponse($paymentMethod)
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
            if (!$user->card_id) {
                return response()->json([
                    'success' => true,
                    'message' => 'No card found',
                    'data' => null
                ]);
            }

            $paymentMethod = PaymentMethod::retrieve($user->card_id);

            return response()->json([
                'success' => true,
                'message' => 'Card retrieved successfully',
                'data' => $this->formatCardResponse($paymentMethod)
            ]);

        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe API errors',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
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