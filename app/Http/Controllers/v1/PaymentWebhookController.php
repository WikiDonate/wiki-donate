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
 * Generic, method-parameterised webhook entrypoint: POST /webhooks/{method}.
 *
 * This is the boundary that replaces the per-gateway webhook routes. The
 * `{method}` segment is a `payment_methods.code`; resolution ignores the
 * `active` flag on purpose (a donor can be redirected moments before an admin
 * disables a method, and that event must still be verified and processed) —
 * see PaymentProviderRegistry::forWebhook().
 *
 * Transitional dispatch: until each gateway's event handling is folded into its
 * provider class, this controller delegates to the existing gateway webhook
 * controllers, which remain reachable through their legacy routes until the
 * PayPal dashboard is repointed. Both routes therefore accept identical
 * payloads during the migration window.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private PaymentProviderRegistry $registry) {}

    /**
     * code => handler class responsible for that method's events.
     *
     * @var array<string, class-string>
     */
    private const HANDLERS = [
        'paypal' => PayPalWebhookController::class,
        'stripe' => StripeWebhookController::class,
    ];

    /**
     * POST /webhooks/{method}
     */
    public function handle(Request $request, string $method): JsonResponse
    {
        $code = strtolower($method);

        try {
            // Fails loudly (PaymentProviderException) for unknown codes or a
            // code whose provider class is missing — a deployment mistake, not
            // a donor-facing 500.
            $this->registry->forWebhook($code);
        } catch (PaymentProviderException $e) {
            Log::critical('Generic webhook rejected: unresolvable payment method.', [
                'method' => $code,
                // Detail stays in the log; the response body is generic.
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unknown payment method',
            ], Response::HTTP_NOT_FOUND);
        }

        $handler = self::HANDLERS[$code] ?? null;

        if ($handler === null || ! class_exists($handler)) {
            Log::critical('Generic webhook has no event handler bound.', ['method' => $code]);

            return response()->json([
                'success' => false,
                'message' => 'Unknown payment method',
            ], Response::HTTP_NOT_FOUND);
        }

        return app($handler)->handleWebhook($request);
    }
}
