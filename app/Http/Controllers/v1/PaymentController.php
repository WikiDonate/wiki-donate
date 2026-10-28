<?php

namespace App\Http\Controllers\v1;

use App\Exceptions\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentCheckoutService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Generic, method-parameterised donor checkout entrypoint.
 *
 *   POST /payment/{method}/create-order
 *   POST /payment/{method}/capture-order
 *   GET  /payment/{method}/status/{reference}
 *
 * `{method}` is a `payment_methods.code`. Resolution goes through
 * PaymentProviderRegistry, so adding a method is a registry row + a provider
 * class — no controller or route changes.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentCheckoutService $checkout) {}

    /**
     * POST /payment/{method}/create-order
     */
    public function createOrder(Request $request, string $method): JsonResponse
    {
        $code = strtolower($method);

        $request->validate([
            'amount' => 'required|numeric|min:0.50|max:500000',
            'currency' => 'nullable|string|size:3',
            'donor_name' => 'nullable|string|max:255',
            'donor_email' => 'nullable|email|max:255',
            'formula_id' => 'nullable|integer|exists:donation_formulas,id',
            'details' => 'nullable|string|max:2000',
        ]);

        try {
            $data = $this->checkout->create($code, $request->all(), $request->user()?->id);

            return response()->json([
                'success' => true,
                'message' => 'Payment order created',
                'data' => $data,
            ], Response::HTTP_OK);
        } catch (PaymentProviderException $e) {
            return $this->providerError($code, $e);
        } catch (RequestException $e) {
            Log::error('Payment provider rejected order creation', [
                'method' => $code,
                'error' => $e->getMessage(),
                'body' => $e->response?->json(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment provider error',
                'errors' => ['The payment provider could not start this payment.'],
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * POST /payment/{method}/capture-order
     */
    public function captureOrder(Request $request, string $method): JsonResponse
    {
        $code = strtolower($method);

        $validated = $request->validate([
            'order_id' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
        ]);

        $reference = (string) ($validated['order_id'] ?? $validated['reference'] ?? '');

        if ($reference === '') {
            throw ValidationException::withMessages([
                'order_id' => 'A gateway reference (order_id) is required.',
            ]);
        }

        try {
            $result = $this->checkout->capture($code, $reference);
        } catch (PaymentProviderException $e) {
            return $this->providerError($code, $e);
        } catch (RequestException $e) {
            Log::error('Payment provider rejected capture', [
                'method' => $code,
                'error' => $e->getMessage(),
                'body' => $e->response?->json(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment provider error',
                'errors' => ['The payment provider could not confirm this payment.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $status = (int) ($result['status'] ?? Response::HTTP_OK);
        unset($result['status']);

        return response()->json([
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? 'Payment status unavailable',
            'errors' => $result['errors'] ?? [],
        ] + (isset($result['data']) ? ['data' => $result['data']] : []), $status);
    }

    /**
     * GET /payment/{method}/status/{reference}
     *
     * Read-only poll for hosted-checkout return pages. Never moves money and
     * never writes a donation (the webhook owns completion).
     */
    public function status(string $method, string $reference): JsonResponse
    {
        $code = strtolower($method);

        try {
            $data = $this->checkout->status($code, $reference);

            return response()->json([
                'success' => true,
                'message' => 'Payment status retrieved',
                'data' => $data,
            ], Response::HTTP_OK);
        } catch (PaymentProviderException $e) {
            return $this->providerError($code, $e);
        } catch (RequestException $e) {
            Log::warning('Payment status lookup failed', [
                'method' => $code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment status unavailable',
                'errors' => ['The payment provider could not return this payment.'],
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Never leak deployment detail (provider class names, retry hints,
     * credential names) to a donor; the actionable text stays in the log.
     */
    private function providerError(string $code, PaymentProviderException $e): JsonResponse
    {
        $status = $e->httpStatus();

        Log::log(
            $status >= 500 ? 'critical' : 'warning',
            'Generic payment endpoint rejected a payment method.',
            ['method' => $code, 'status' => $status, 'error' => $e->getMessage()]
        );

        $message = match ($status) {
            Response::HTTP_UNPROCESSABLE_ENTITY => 'This operation is not supported for this payment method.',
            Response::HTTP_SERVICE_UNAVAILABLE, Response::HTTP_BAD_GATEWAY => 'This payment method is temporarily unavailable.',
            default => 'This payment method is not available.',
        };

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$message],
        ], $status);
    }
}
