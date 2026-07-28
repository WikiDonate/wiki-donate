<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Symfony\Component\HttpFoundation\Response;

class DonateController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function donateNow(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = auth()->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'errors' => ['You must be logged in to donate.'],
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->card_id || ! $user->customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'No saved payment method found',
                'errors' => ['Please add a payment method first'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $request->amount * 100,
                'currency' => 'usd',
                'customer' => $user->customer_id,
                'payment_method' => $user->card_id,
                'off_session' => true,
                'confirm' => true,
            ]);

            $payment = Payment::recordPayment([
                'user_id' => $user->id,
                'payment_id' => $paymentIntent->id,
                'customer_id' => $user->customer_id,
                'amount' => $request->amount,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status,
            ]);

            Cache::store('file')->forget('dashboard');

            return response()->json([
                'success' => true,
                'message' => 'Donation processed successfully',
                'data' => $payment,
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
