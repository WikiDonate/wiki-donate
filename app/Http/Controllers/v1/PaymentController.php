<?php

namespace App\Http\Controllers\v1;

use App\Exceptions\PaymentProviderException;
use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Generic, method-parameterised donor checkout entrypoint:
 *
 *   POST /payment/{method}/create-order
 *   POST /payment/{method}/capture-order
 *
 * `{method}` is a `payment_methods.code`. Resolution goes through
 * PaymentProviderRegistry::forCode(), which enforces `active` for new flows and
 * fails LOUDLY (never a donor-facing 500) when a code has no provider bound.
 *
 * Transitional dispatch: the gateway controllers still hold the per-gateway
 * implementation detail. This controller resolves the method, delegates, and
 * normalises the result into one shape the SPA can consume regardless of
 * gateway — so adding a method is a registry row + a provider class, with no
 * front-end change.
 *
 * The legacy per-gateway routes (/paypal/create-order, /stripe/checkout, ...)
 * remain wired for in-flight clients and the PayPal dashboard until it is
 * repointed; both paths share the same handler code.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentProviderRegistry $registry) {}

    /**
     * code => [controller class, create method, capture method|null].
     *
     * A null capture method means the gateway confirms out-of-band (a webhook),
     * so client-side capture is not offered for it.
     *
     * @var array<string, array{0: class-string, 1: string, 2: ?string}>
     */
    private const HANDLERS = [
        'paypal' => [PayPalController::class, 'createOrder', 'captureOrder'],
        'stripe' => [StripeController::class, 'createCheckoutSession', null],
    ];

    /**
     * POST /payment/{method}/create-order
     */
    public function createOrder(Request $request, string $method): JsonResponse
    {
        $code = strtolower($method);

        $handler = $this->handler($code);
        if ($handler instanceof JsonResponse) {
            return $handler;
        }

        [$class, $createMethod] = $handler;

        /** @var JsonResponse $delegated */
        $delegated = app($class)->{$createMethod}($request);

        return $this->normalise($delegated, $code, $request);
    }

    /**
     * POST /payment/{method}/capture-order
     */
    public function captureOrder(Request $request, string $method): JsonResponse
    {
        $code = strtolower($method);

        $handler = $this->handler($code);
        if ($handler instanceof JsonResponse) {
            return $handler;
        }

        [, , $captureMethod] = $handler;

        if ($captureMethod === null) {
            return response()->json([
                'success' => false,
                'message' => 'This payment method is confirmed by the provider, not by client capture.',
                'errors' => ['Capture is not supported for this payment method.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var JsonResponse $delegated */
        $delegated = app($handler[0])->{$captureMethod}($request);

        return $this->normalise($delegated, $code, $request);
    }

    /**
     * Resolve the method to its handler, or return the error response.
     *
     * @return array{0: class-string, 1: string, 2: ?string}|JsonResponse
     */
    private function handler(string $code): array|JsonResponse
    {
        try {
            // Enforces `active` for new donor flows and throws loudly for an
            // unknown / unbound code with no provider class.
            $this->registry->forCode($code);
        } catch (PaymentProviderException $e) {
            // A deployment mistake (unbound provider, missing class, disabled
            // method). Log the diagnostic detail; never echo it to the donor.
            Log::warning('Generic payment endpoint rejected: unresolvable method.', [
                'method' => $code,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'This payment method is not available.',
                'errors' => ['This payment method is not available.'],
            ], Response::HTTP_NOT_FOUND);
        }

        $handler = self::HANDLERS[$code] ?? null;

        if ($handler === null) {
            // Registry row + provider exist but no checkout flow is wired yet:
            // a deployment gap, not a donor error. Detail stays in the log.
            Log::critical('Payment method has no checkout handler wired.', ['method' => $code]);

            return response()->json([
                'success' => false,
                'message' => 'This payment method is not available for checkout.',
                'errors' => ['This payment method is not available for checkout.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $handler;
    }

    /**
     * Add the generic fields every caller can rely on, leaving the
     * gateway-specific ones intact for back-compat.
     */
    private function normalise(JsonResponse $delegated, string $code, Request $request): JsonResponse
    {
        $payload = json_decode($delegated->getContent(), true);

        if (! is_array($payload)) {
            return $delegated;
        }

        if (($payload['success'] ?? false) !== true) {
            return $delegated;
        }

        $data = $payload['data'] ?? [];

        // One reference field regardless of gateway: a PayPal order id, a
        // Stripe checkout session id, or — on capture — the reference the
        // caller sent in. The SPA only ever needs `reference`.
        $data['payment_method'] = $code;
        $data['reference'] = $data['order_id']
            ?? $data['session_id']
            ?? $data['reference']
            ?? $request->input('order_id')
            ?? null;

        $payload['data'] = $data;

        return response()->json($payload, $delegated->getStatusCode(), $delegated->headers->all());
    }
}
