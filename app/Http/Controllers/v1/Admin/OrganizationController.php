<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrganizationVerificationMail;
use App\Models\Organization;
use App\Models\TransactionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class OrganizationController extends Controller
{
    /**
     * List registered organizations.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()
            ->with(['createdBy:id,username', 'updatedBy:id,username'])
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('payout_status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('ein', 'like', "%{$q}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $query->paginate($perPage)->through(fn ($org) => $this->transform($org)),
        ], Response::HTTP_OK);
    }

    /**
     * Show a single organization.
     */
    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully',
            'data' => $this->transform($organization->load('createdBy:id,username', 'updatedBy:id,username')),
        ], Response::HTTP_OK);
    }

    /**
     * Update an organization (PayPal email and basic fields).
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'paypal_email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $before = $organization->only(['paypal_email', 'payout_status', 'verified_at']);
        $paypalChanged = $request->has('paypal_email') && $request->input('paypal_email') !== $organization->paypal_email;

        $organization->fill($request->only(['paypal_email', 'city', 'state', 'country']));

        if ($paypalChanged) {
            $organization->payout_status = 'unverified';
            $organization->verified_at = null;
        }

        $organization->updated_by_id = $request->user()->id;
        $organization->save();

        TransactionLog::record(
            'organization.updated',
            $organization,
            before: $before,
            after: $organization->only(['paypal_email', 'payout_status', 'verified_at']),
            actorId: $request->user()->id,
            note: $paypalChanged ? 'PayPal email changed; verification reset.' : null,
        );

        if ($paypalChanged) {
            TransactionLog::record(
                'organization.verification_reset',
                $organization,
                before: $before,
                after: $organization->only(['payout_status', 'verified_at']),
                actorId: $request->user()->id,
                note: 'PayPal email changed.',
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully',
            'data' => $this->transform($organization->load('createdBy:id,username', 'updatedBy:id,username')),
        ], Response::HTTP_OK);
    }

    /**
     * Mark an organization as verified for payouts.
     */
    public function verify(Request $request, Organization $organization): JsonResponse
    {
        if (empty($organization->paypal_email)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot verify an organization without a PayPal receiving email.',
                'errors' => ['paypal_email' => 'PayPal email is required before verification.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $before = $organization->only(['payout_status', 'verified_at']);

        $organization->payout_status = 'verified';
        $organization->verified_at = now();
        $organization->updated_by_id = $request->user()->id;
        $organization->save();

        TransactionLog::record(
            'organization.verified',
            $organization,
            before: $before,
            after: $organization->only(['payout_status', 'verified_at']),
            actorId: $request->user()->id,
        );

        try {
            Mail::to($organization->paypal_email)
                ->queue(new OrganizationVerificationMail($organization));
        } catch (\Throwable $e) {
            // Queuing failure must not block the verification response.
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization verified successfully',
            'data' => $this->transform($organization->load('createdBy:id,username', 'updatedBy:id,username')),
        ], Response::HTTP_OK);
    }

    /**
     * Unverify an organization.
     */
    public function unverify(Request $request, Organization $organization): JsonResponse
    {
        $before = $organization->only(['payout_status', 'verified_at']);

        $organization->payout_status = 'unverified';
        $organization->verified_at = null;
        $organization->updated_by_id = $request->user()->id;
        $organization->save();

        TransactionLog::record(
            'organization.unverified',
            $organization,
            before: $before,
            after: $organization->only(['payout_status', 'verified_at']),
            actorId: $request->user()->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization unverified successfully',
            'data' => $this->transform($organization->load('createdBy:id,username', 'updatedBy:id,username')),
        ], Response::HTTP_OK);
    }

    private function transform(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'uuid' => $organization->uuid,
            'name' => $organization->name,
            'ein' => $organization->ein,
            'city' => $organization->city,
            'state' => $organization->state,
            'country' => $organization->country,
            'paypal_email' => $organization->paypal_email,
            'payout_status' => $organization->payout_status,
            'verified_at' => $organization->verified_at?->toDateTimeString(),
            'created_at' => $organization->created_at?->toDateTimeString(),
            'updated_at' => $organization->updated_at?->toDateTimeString(),
            'created_by' => $organization->createdBy?->username,
            'updated_by' => $organization->updatedBy?->username,
        ];
    }
}
