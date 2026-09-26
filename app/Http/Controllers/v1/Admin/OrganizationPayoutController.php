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
                'message' => 'Payout recorded successfully',
                'data' => $this->transform($payout->load('actor:id,uuid,username', 'formula:id,uuid,name')),
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            'amount' => (float) $payout->amount,
            'currency' => $payout->currency,
            'type' => $payout->type,
            'status' => $payout->status,
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
