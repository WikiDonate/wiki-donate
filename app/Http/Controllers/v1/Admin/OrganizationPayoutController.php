<?php

namespace App\Http\Controllers\v1\Admin;

use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Rules\SupportedCurrency;
use App\Services\Payout\OrganizationPayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

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
            // Rename-safe, like the balance math: match the key OR the resolved
            // registry row, so renaming an org does not hide its ledger.
            $key = OrganizationPayout::makeKey($request->organization);
            $formula = $request->filled('donation_formula_id')
                ? DonationFormula::find($request->input('donation_formula_id'))
                : null;

            $organizationId = $formula
                ? $this->payouts->resolveOrganizationId($formula, (string) $request->organization)
                : Organization::query()
                    ->where('normalized_name', Organization::normalizeName((string) $request->organization))
                    ->value('id');

            $query->where(function ($q) use ($key, $organizationId) {
                $q->where('organization_key', $key);

                if ($organizationId !== null) {
                    $q->orWhere('organization_id', $organizationId);
                }
            });
        }

        $perPage = (int) ($request->input('per_page') ?? 20);
        $perPage = min(max($perPage, 1), 100);

        return response()->json([
            'success' => true,
            'message' => 'Payouts retrieved successfully',
            // created_at, not paid_at: pending/reversed rows have no paid_at
            // and would sort unpredictably.
            'data' => $query->latest('created_at')->paginate($perPage)->through(fn ($p) => $this->transform($p)),
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
            // The transaction cost is enforced server-side: a client may not
            // submit a fee or net figure at all (prohibited => 422).
            'net_amount' => 'prohibited',
            'net' => 'prohibited',
            'fee_amount' => 'prohibited',
            'fee_percent' => 'prohibited',
            'fee' => 'prohibited',
            'currency' => ['required', 'string', new SupportedCurrency],
            'note' => 'nullable|string|max:65535',
            // Payout rail is data-driven: only an active, payout-capable
            // registry code may be used. Omitted => the historical default.
            'payment_method' => ['nullable', 'string', Rule::exists('payment_methods', 'code')
                ->where(fn ($q) => $q->where('active', true)->whereIn('type', ['payout', 'both']))],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $payout = $this->payouts->createPayout(
                [
                    ...$request->only(['donation_formula_id', 'organization_name', 'amount', 'note']),
                    'currency' => strtolower($request->input('currency')),
                    'payment_method' => $request->input('payment_method'),
                ],
                $request->user()->id,
            );

            return response()->json([
                'success' => true,
                'message' => 'Payout submitted',
                'data' => $this->transform($payout->load('actor:id,uuid,username', 'formula:id,uuid,name')),
            ], Response::HTTP_CREATED);
        } catch (UserFacingException $e) {
            // Expected, admin-readable rejection (overpay, guard, fee rules).
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            // Unexpected: log the detail, never leak internals to the client.
            Log::error('Payout creation failed', [
                'donation_formula_id' => $request->input('donation_formula_id'),
                'organization_name' => $request->input('organization_name'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The payout could not be submitted. Please try again or contact support.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Reconcile pending payout rows against the PayPal Payouts API.
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            $result = $this->payouts->syncPending();

            return response()->json([
                'success' => true,
                'message' => 'Payout statuses synced',
                'data' => $result['changed']->map(fn ($p) => $this->transform($p->load('formula:id,uuid,name'))),
                'meta' => [
                    'checked' => $result['checked'],
                    'changed' => $result['changed']->count(),
                    'errors' => $result['failed'],
                ],
            ], Response::HTTP_OK);
        } catch (Throwable $e) {
            Log::error('Payout sync failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Could not sync payout statuses. Please try again or contact support.',
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
            // amount = GROSS; fee_amount is the enforced transaction cost and
            // net_amount is what the organization actually received.
            'amount' => (float) $payout->amount,
            'fee_percent' => $payout->fee_percent === null ? null : (float) $payout->fee_percent,
            'fee_amount' => $payout->fee_amount === null ? null : (float) $payout->fee_amount,
            'net_amount' => $payout->net_amount === null ? null : (float) $payout->net_amount,
            'currency' => $payout->currency,
            'type' => $payout->type,
            'status' => $payout->status,
            'payment_method' => $payout->payment_method,
            'provider_status' => $payout->provider_status,
            'failure_reason' => $payout->failure_reason,
            'payout_batch_id' => $payout->payout_batch_id,
            'payout_item_id' => $payout->payout_item_id,
            'paid_at' => $payout->paid_at?->toIso8601String(),
            'submitted_at' => $payout->submitted_at?->toIso8601String(),
            'reversed_at' => $payout->reversed_at?->toIso8601String(),
            'created_at' => $payout->created_at?->toIso8601String(),
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
