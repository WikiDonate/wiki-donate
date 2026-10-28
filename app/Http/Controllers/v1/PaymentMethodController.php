<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCredentials;
use App\Services\Payment\PaymentProviderRegistry;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public registry endpoint backing every method-aware UI (donor checkout AND
 * admin filters/badges/charts).
 *
 * Only rows with `active = 1` **and a bound provider class** are returned:
 * flipping a method's `active` flag is what surfaces it on the site, and
 * inactive methods stay persisted so in-flight webhooks are still handled
 * server-side. An active row with no provider class is a deployment mistake —
 * it is excluded from the donor picker and logged, never offered.
 *
 * The payload is intentionally non-secret — gateway credentials live in the
 * encrypted `credentials` column and never leave the server. Only the
 * browser-safe `public_key` (PayPal client ID / Stripe publishable key) is
 * projected; see App\Services\Payment\PaymentCredentials.
 */
class PaymentMethodController extends Controller
{
    public function __construct(
        private PaymentCredentials $credentials,
        private PaymentProviderRegistry $registry,
    ) {}

    /**
     * GET /v1/payment-methods
     *
     * Returns the active payment-method registry in `sort_order` order:
     *  - `methods`       → income-capable rows (donor checkout pickers)
     *  - `payout_methods`→ payout-capable rows (admin payouts rail)
     *
     * The payout list is a subset of the same active registry, so admins can
     * never select a method a donor could not have paid with.
     */
    public function index(): JsonResponse
    {
        try {
            $methods = PaymentMethod::query()
                ->active()
                ->ordered()
                ->get()
                ->filter(fn (PaymentMethod $method) => $this->isServable($method))
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Payment methods retrieved successfully.',
                'data' => [
                    'methods' => $methods
                        ->filter(fn (PaymentMethod $method) => $method->isIncomeCapable())
                        ->map(fn (PaymentMethod $method) => $this->format($method))
                        ->values()
                        ->all(),
                    'payout_methods' => $methods
                        ->filter(fn (PaymentMethod $method) => $method->isPayoutCapable())
                        ->map(fn (PaymentMethod $method) => $this->format($method))
                        ->values()
                        ->all(),
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load payment methods.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Whether a row can actually be served: active AND backed by a provider
     * class. Active-but-unbound rows are a deployment mistake, so they are
     * logged once per request instead of being offered to donors.
     */
    private function isServable(PaymentMethod $method): bool
    {
        if ($this->registry->hasBinding($method->code)) {
            return true;
        }

        Log::critical('Payment method is active but has no provider binding — hiding it from the registry.', [
            'code' => $method->code,
        ]);

        return false;
    }

    /**
     * GET /v1/admin/payment-methods
     *
     * Full registry for admin filters/labels — includes inactive methods so
     * historical transactions (e.g. the Stripe rows left behind when Stripe was
     * disabled as a donation method) remain filterable.
     */
    public function adminIndex(): JsonResponse
    {
        try {
            $methods = PaymentMethod::query()->ordered()->get();

            return response()->json([
                'success' => true,
                'message' => 'Payment method registry retrieved successfully.',
                'data' => [
                    'methods' => $methods
                        ->map(fn (PaymentMethod $method) => $this->format($method) + [
                            'active' => (bool) $method->active,
                            'available' => $this->registry->hasBinding($method->code),
                        ])
                        ->values()
                        ->all(),
                ],
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load the payment method registry.',
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Non-secret projection of a registry row for the SPA.
     *
     * `type` is exposed raw (income|payout|both) so the two lists stay
     * self-describing, while `capabilities` gives the UI booleans without
     * duplicating the enum semantics in JavaScript.
     *
     * @return array<string, mixed>
     */
    private function format(PaymentMethod $method): array
    {
        return [
            'code' => $method->code,
            'label' => $method->label,
            'type' => $method->type,
            'sort_order' => $method->sort_order,
            // Browser-safe credential only (PayPal client id / Stripe pk).
            'public_key' => $this->credentials->publicKey($method->code),
            'capabilities' => [
                'income' => $method->isIncomeCapable(),
                'payout' => $method->isPayoutCapable(),
            ],
        ];
    }

    /**
     * GET /v1/payment-methods/{code}/public-key
     *
     * Browser-safe credential for a registered method (PayPal client ID or
     * Stripe publishable key). `active` is deliberately ignored: the billing
     * page still saves cards through Stripe while Stripe is disabled as a
     * donation method. Secret keys never leave the server.
     *
     * Fallback for non-active methods not present in the index payload.
     */
    public function publicKey(string $code): JsonResponse
    {
        $method = PaymentMethod::query()->where('code', $code)->first();

        if (! $method) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown payment method.',
                'errors' => ["Payment method '{$code}' is not registered."],
            ], Response::HTTP_NOT_FOUND);
        }

        $publicKey = $this->credentials->publicKey($method->code);

        if (! $publicKey) {
            return response()->json([
                'success' => false,
                'message' => 'Public key is not configured for this payment method.',
                'errors' => ["Payment method '{$code}' has no browser-safe credential."],
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'Public key retrieved successfully.',
            'data' => [
                'code' => $method->code,
                'public_key' => $publicKey,
            ],
        ], Response::HTTP_OK);
    }
}
