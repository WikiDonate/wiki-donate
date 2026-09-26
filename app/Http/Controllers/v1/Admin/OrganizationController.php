<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrganizationVerificationMail;
use App\Models\Organization;
use App\Models\TransactionLog;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Admin organization registry management.
 *
 * Lists orgs (with payout readiness), edits the PayPal receiving email and
 * verifies orgs (payout_status → verified + verified_at). Every mutation is
 * written to the append-only transaction log with before/after values.
 */
class OrganizationController extends Controller
{
    /**
     * Paginated list with filters on payout status, EIN and search text.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Organization::query();

        if ($request->filled('status')) {
            $query->where('payout_status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('ein', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('state', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $query->latest('id')->paginate($perPage),
        ], Response::HTTP_OK);
    }

    /**
     * Update the PayPal receiving email for an organization.
     */
    public function updatePaypalEmail(Request $request, int $id): JsonResponse
    {
        $org = Organization::find($id);

        if (! $org) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'errors' => ['Organization not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        $validator = Validator::make($request->all(), [
            'paypal_email' => 'nullable|email|max:254',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $newEmail = $request->filled('paypal_email')
            ? mb_strtolower(trim($request->input('paypal_email')))
            : null;

        if ($org->paypal_email === $newEmail) {
            return response()->json([
                'success' => true,
                'message' => 'PayPal email unchanged',
                'data' => $org,
            ], Response::HTTP_OK);
        }

        $before = $org->paypal_email;

        $org->update(['paypal_email' => $newEmail]);

        TransactionLog::record('organization.paypal_email_changed', [
            'subject_type' => Organization::class,
            'subject_id' => $org->id,
            'actor_id' => $request->user()->id,
            'actor_role' => 'Admin',
            'message' => "PayPal email for {$org->name} changed "
                .($before ? "from {$before} " : '')
                .($newEmail ? "to {$newEmail}" : 'to (empty)'),
            'before' => ['paypal_email' => $before],
            'after' => ['paypal_email' => $newEmail],
        ]);

        // A changed destination invalidates verification: the new address has
        // never been confirmed. Keep verified status but require re-verify?
        // Per product decision: changing email resets payout_status to
        // unverified so admins re-confirm the new destination.
        if ($org->payout_status === 'verified' && $before !== $newEmail) {
            $org->update([
                'payout_status' => 'unverified',
                'verified_at' => null,
            ]);

            TransactionLog::record('organization.verification_reset', [
                'subject_type' => Organization::class,
                'subject_id' => $org->id,
                'actor_id' => $request->user()->id,
                'actor_role' => 'Admin',
                'message' => "Verification reset for {$org->name} after PayPal email change",
                'before' => ['payout_status' => 'verified', 'verified_at' => $org->verified_at?->toDateTimeString()],
                'after' => ['payout_status' => 'unverified', 'verified_at' => null],
            ]);
        }

        $org->refresh();

        return response()->json([
            'success' => true,
            'message' => 'PayPal email updated successfully',
            'data' => $org,
        ], Response::HTTP_OK);
    }

    /**
     * Mark an organization as verified for payouts.
     */
    public function verify(Request $request, int $id): JsonResponse
    {
        $org = Organization::find($id);

        if (! $org) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'errors' => ['Organization not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        if (! filled($org->paypal_email)) {
            return response()->json([
                'success' => false,
                'message' => 'Set a PayPal receiving email before verifying this organization.',
                'errors' => ['A PayPal receiving email is required before verification.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $wasVerified = $org->payout_status === 'verified';
        $beforeStatus = $org->payout_status;
        $beforeVerifiedAt = $org->verified_at?->toDateTimeString();

        $org->update([
            'payout_status' => 'verified',
            'verified_at' => now(),
        ]);

        TransactionLog::record('organization.verified', [
            'subject_type' => Organization::class,
            'subject_id' => $org->id,
            'actor_id' => $request->user()->id,
            'actor_role' => 'Admin',
            'message' => "Organization verified for payouts: {$org->name} ({$org->paypal_email})",
            'before' => ['payout_status' => $beforeStatus, 'verified_at' => $beforeVerifiedAt],
            'after' => [
                'payout_status' => $org->payout_status,
                'verified_at' => $org->verified_at?->toDateTimeString(),
            ],
        ]);

        // Optional confirmation email to the PayPal address — admins confirm
        // the destination belongs to the org before money moves. Failures are
        // logged but never fail the verification itself.
        try {
            Mail::to($org->paypal_email)->queue(new OrganizationVerificationMail($org));
        } catch (Exception $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => $wasVerified
                ? 'Organization was already verified'
                : 'Organization verified successfully',
            'data' => $org,
        ], Response::HTTP_OK);
    }

    /**
     * Unverify an organization (admin-triggered, e.g. failed confirmation).
     */
    public function unverify(Request $request, int $id): JsonResponse
    {
        $org = Organization::find($id);

        if (! $org) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'errors' => ['Organization not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        if ($org->payout_status !== 'verified') {
            return response()->json([
                'success' => true,
                'message' => 'Organization is already unverified',
                'data' => $org,
            ], Response::HTTP_OK);
        }

        $beforeStatus = $org->payout_status;
        $beforeVerifiedAt = $org->verified_at?->toDateTimeString();

        $org->update([
            'payout_status' => 'unverified',
            'verified_at' => null,
        ]);

        TransactionLog::record('organization.unverified', [
            'subject_type' => Organization::class,
            'subject_id' => $org->id,
            'actor_id' => $request->user()->id,
            'actor_role' => 'Admin',
            'message' => "Organization unverified: {$org->name}",
            'before' => ['payout_status' => $beforeStatus, 'verified_at' => $beforeVerifiedAt],
            'after' => ['payout_status' => 'unverified', 'verified_at' => null],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Organization unverified successfully',
            'data' => $org,
        ], Response::HTTP_OK);
    }
}
