<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DonationFormula;
use App\Models\OrganizationPayout;
use App\Services\Payout\OrganizationPayoutService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class OrganizationPayoutController extends Controller
{
    public function __construct(private OrganizationPayoutService $payouts) {}

    /**
     * Payout ledger list (by formula, or all if no filter).
     */
    public function index(Request $request): JsonResponse
    {
        $query = OrganizationPayout::with(['formula:id,uuid,name', 'actor:id,uuid,username']);

        if ($request->filled('donation_formula_id')) {
            $query->where('donation_formula_id', $request->donation_formula_id);
        }

        if ($request->filled('organization')) {
            $query->where('organization_name', $request->organization);
        }

        $perPage = (int) ($request->input('per_page') ?? 20);
        $perPage = min(max($perPage, 1), 100);

        return response()->json([
            'success' => true,
            'message' => 'Payouts retrieved successfully',
            'data' => $query->latest('paid_at')->paginate($perPage)->through(fn ($p) => $this->transform($p)),
        ], Response::HTTP_OK);
    }

    /**
     * List payable allocations (formula_id + org key, live owed/paid/balance).
     */
    public function allocations(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Payable allocations retrieved successfully',
            'data' => $this->payouts->payableAllocations(),
        ], Response::HTTP_OK);
    }

    /**
     * Payout ledger history for one allocation.
     */
    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'donation_formula_id' => 'required|integer|exists:donation_formulas,id',
            'organization' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $history = $this->payouts->history(
            DonationFormula::findOrFail($request->donation_formula_id),
            $request->organization,
        );

        return response()->json([
            'success' => true,
            'message' => 'Payout history retrieved successfully',
            'data' => $history->map(fn ($p) => $this->transform($p)),
        ], Response::HTTP_OK);
    }

    /**
     * Create a payout (full or partial) against the live balance.
     *
     * The amount is reserved in the ledger, then the real transfer is
     * submitted to the PayPal Payouts API. The returned row is paid when
     * PayPal confirmed immediately, pending while the transfer clears, or
     * failed when PayPal rejected it.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'donation_formula_id' => 'required|integer|exists:donation_formulas,id',
            'organization_name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'note' => 'nullable|string|max:65535',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $payout = $this->payouts->createPayout(
                [
                    ...$request->only(['donation_formula_id', 'organization_name', 'amount', 'note']),
                    'currency' => strtolower($request->input('currency')),
                ],
                $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Payout submitted to PayPal',
                'data' => $this->transform($payout->load('actor:id,uuid,username', 'formula:id,uuid,name')),
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Reconcile pending payout rows against the PayPal Payouts API.
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            $changed = $this->payouts->syncPending();

            return response()->json([
                'success' => true,
                'message' => 'Payout statuses synced',
                'data' => $changed->map(fn ($p) => $this->transform($p->load('formula:id,uuid,name'))),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function transform(OrganizationPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'uuid' => $payout->uuid,
            'donation_formula_id' => $payout->donation_formula_id,
            'formula' => $payout->relationLoaded('formula') && $payout->formula
                ? ['id' => $payout->formula->id, 'uuid' => $payout->formula->uuid, 'name' => $payout->formula->name]
                : null,
            'organization_name' => $payout->organization_name,
            'organization_id' => $payout->organization_id,
            'destination_paypal_email' => $payout->destination_paypal_email,
            'amount' => (float) $payout->amount,
            'currency' => $payout->currency,
            'type' => $payout->type,
            'status' => $payout->status,
            'provider_status' => $payout->provider_status,
            'failure_reason' => $payout->failure_reason,
            'payout_batch_id' => $payout->payout_batch_id,
            'payout_item_id' => $payout->payout_item_id,
            'paid_at' => $payout->paid_at?->format('Y-m-d H:i:s'),
            'note' => $payout->note,
            'actor' => $payout->relationLoaded('actor') && $payout->actor
                ? ['uuid' => $payout->actor->uuid, 'username' => $payout->actor->username]
                : null,
        ];
    }

    private function validationError($validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
