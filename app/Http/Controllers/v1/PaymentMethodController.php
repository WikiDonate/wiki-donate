<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Public registry endpoint backing every method-aware UI (donor checkout AND
 * admin filters/badges/charts).
 *
 * Only rows with `active = 1` are ever returned: flipping a method's `active`
 * flag is what surfaces it on the site, and inactive methods stay persisted so
 * in-flight webhooks are still handled server-side.
 *
 * The payload is intentionally non-secret — credentials live in .env and are
 * referenced by NAME only (see PaymentMethod::webhookSecretRef()).
 */
class PaymentMethodController extends Controller
{
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
                ->get();

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
            'capabilities' => [
                'income' => $method->isIncomeCapable(),
                'payout' => $method->isPayoutCapable(),
            ],
        ];
    }
}
