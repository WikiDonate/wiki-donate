<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Mail\DonationConfirmationMail;
use App\Models\Donate;
use App\Models\Payment;
use App\Models\PaymentLog;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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

    /**
     * @OA\Post(
     *     path="/api/v1/donate",
     *     summary="Donate to the Wiki",
     *     operationId="donate",
     *     tags={"Donate"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name", "email", "cardNumber", "expiryMonth", "expiryYear", "cvv", "amount"},
     *
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="johndoe@example.com", format="email", description="Email of the donor"),
     *             @OA\Property(property="cardNumber", type="integer", example="1234567890123456", description="Donor's card number"),
     *             @OA\Property(property="expiryMonth", type="integer", example="12", description="Donor's card expiry month"),
     *             @OA\Property(property="expiryYear", type="integer", example="2030", description="Donor's card expiry year"),
     *             @OA\Property(property="cvv", type="integer", example="123", description="Donor's card CVV"),
     *             @OA\Property(property="amount", type="integer", example="1", description="Amount of donation"),
     *         )
     *     ),
     *
     *    @OA\Response(
     *         response=201,
     *         description="Success response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object"
     *                 )
     *             ),
     *         ),
     *     ),
     *
     *      @OA\Response(
     *         response=400,
     *         description="Error response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=417,
     *         description="Exception error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Exception message"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     * )
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|digits:12',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zipCode' => 'required|string|max:20',
            'country' => 'required|string|max:255',
            'cardNumber' => 'required|digits:16',
            'expiryMonth' => 'required|digits:2',
            'expiryYear' => 'required|digits:4',
            'cvv' => 'required|digits_between:3,4',
            'amount' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            Donate::create([
                'user_id' => auth()->user()->id ?? null,
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'zip_code' => $request->zipCode,
                'country' => $request->country,
                'card_number' => $request->cardNumber,
                'expiry_month' => $request->expiryMonth,
                'expiry_year' => $request->expiryYear,
                'cvv' => $request->cvv,
                'amount' => $request->amount,
            ]);

            // Send email
            if (! empty($request->email)) {
                $mailData = [
                    'name' => $request->name,
                    'amount' => $request->amount,
                    'date' => now()->toFormattedDateString(),
                ];

                Mail::to($request->email)->queue(new DonationConfirmationMail($mailData));

            }

            return response()->json([
                'success' => true,
                'message' => 'Donate details saved successfully.',
                'data' => [],
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function donateNow(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'cause_id' => 'required|exists:causes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = auth()->user();

        if (! $user->card_id || ! $user->customer_id) {
            return response()->json([
                'success' => false,
                'message' => 'No saved payment method found',
                'errors' => ['Please add a payment method first'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Create payment intent
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
                'cause_id' => $request->cause_id,
            ]);

            // Create payment logs for NGOS
            $paymentLogs = PaymentLog::distributeAndCreatePaymentLogs($payment, $request->cause_id, $user->id);

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

    public function recordPaymentApi(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'payment_id' => 'required',
            'customer_id' => 'required',
            'amount' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'status' => 'required|string',
            'cause_id' => 'nullable|exists:causes,id',
            'source' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {

            $user = auth()->user();

            $paymentData = [
                'user_id' => $request->user_id ?? $user->id,
                'payment_id' => $request->payment_id,
                'customer_id' => $request->customer_id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'status' => $request->status,
                'cause_id' => $request->cause_id,
                'source' => $request->source ?? 'stripe',
            ];

            // 3. Record the payment
            $payment = Payment::recordPayment($paymentData);

            // 4. Create payment logs
            $paymentLogs = PaymentLog::distributeAndCreatePaymentLogs($payment, $request->cause_id, $user->id);

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully',
                'data' => $payment,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to record payment',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
