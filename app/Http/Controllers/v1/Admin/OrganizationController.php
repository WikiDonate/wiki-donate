<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\OrganizationVerificationMail;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\OrganizationPayout;
use App\Models\PaymentMethod;
use App\Models\TransactionLog;
use App\Services\Charity\VerifiedCharitySearchService;
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

        if ($request->filled('search') || $request->filled('q')) {
            $search = $request->input('search', $request->input('q'));
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
     * Directory search for the Add-organization modal.
     *
     * Same charity directory as the public autocomplete, but every hit that is
     * already in the registry is removed — verified or unverified. An admin must
     * edit the existing row instead of creating a duplicate.
     */
    public function search(Request $request, VerifiedCharitySearchService $service): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'message' => 'Query too short',
                'data' => [],
            ], Response::HTTP_OK);
        }

        return response()->json([
            'success' => true,
            'message' => 'Charity directory retrieved successfully',
            'data' => $service->search($query, excludeExisting: true),
        ], Response::HTTP_OK);
    }

    /**
     * Show a single organization.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $org = Organization::find($id);

        if (! $org) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'errors' => ['Organization not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully',
            'data' => $org,
        ], Response::HTTP_OK);
    }

    /**
     * Create an organization from the admin "Add organization" modal.
     *
     * Mirrors the update rules: EIN (nullable) and normalized_name are unique,
     * the PayPal email must be valid, and every payout method must reference
     * an active, payout-capable payment_methods row. `normalized_name` is
     * always derived server-side and never accepted from the client.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'ein' => 'nullable|string|max:20|unique:organizations,ein',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'paypal_email' => 'nullable|email|max:254',
            'payout_methods' => 'nullable|array',
            'payout_methods.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
                'field_errors' => $validator->errors()->toArray(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name')));
        $normalized = Organization::normalizeName($name);
        $ein = $this->normalizeEin($request->input('ein'));

        // EIN uniqueness is also enforced by the DB index, but a clear 422 is
        // friendlier than a constraint violation.
        if ($ein !== null && Organization::where('ein', $ein)->exists()) {
            return $this->validationError(['ein' => 'An organization with this EIN already exists.']);
        }

        if (Organization::where('normalized_name', $normalized)->exists()) {
            return $this->validationError(['name' => 'An organization with this name already exists.']);
        }

        $methods = $request->has('payout_methods')
            ? $request->input('payout_methods')
            : [Organization::DEFAULT_PAYOUT_METHOD];

        if ($error = $this->payoutMethodError($methods)) {
            return $this->validationError(['payout_methods' => $error]);
        }

        $org = Organization::create([
            'name' => $name,
            'normalized_name' => $normalized,
            'ein' => $ein,
            'city' => $request->input('city'),
            'state' => $request->input('state'),
            'country' => $request->filled('country') ? $request->input('country') : 'US',
            'website' => $request->filled('website') ? trim((string) $request->input('website')) : null,
            'paypal_email' => $request->filled('paypal_email')
                ? mb_strtolower(trim((string) $request->input('paypal_email')))
                : null,
            'payout_methods' => $this->normalizePayoutMethods($methods),
            'payout_status' => 'unverified',
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]);

        TransactionLog::record('organization.created', [
            'subject_type' => Organization::class,
            'subject_id' => $org->id,
            'actor_id' => $request->user()->id,
            'actor_role' => 'Admin',
            'message' => "Organization created: {$org->name}",
            'after' => $org->only([
                'name', 'normalized_name', 'ein', 'city', 'state', 'country',
                'website', 'paypal_email', 'payout_methods', 'payout_status',
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Organization created successfully',
            'data' => $org->refresh(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Delete an organization, refusing when historical records still point at
     * it (payout ledger rows or formula rows). History must stay reportable,
     * so we never cascade-delete financial references.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $org = Organization::find($id);

        if (! $org) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'errors' => ['Organization not found'],
            ], Response::HTTP_NOT_FOUND);
        }

        $payoutCount = OrganizationPayout::where('organization_id', $org->id)->count();
        $formulaCount = $this->formulaReferenceCount($org);

        if ($payoutCount > 0 || $formulaCount > 0) {
            $parts = [];
            if ($payoutCount > 0) {
                $parts[] = "{$payoutCount} payout(s)";
            }
            if ($formulaCount > 0) {
                $parts[] = "{$formulaCount} donation formula(s)";
            }

            return response()->json([
                'success' => false,
                'message' => 'Organization has payout or formula history and cannot be deleted; unverify it instead.',
                'errors' => ['Referenced by '.implode(' and ', $parts).'.'],
            ], Response::HTTP_CONFLICT);
        }

        $before = $org->only([
            'name', 'normalized_name', 'ein', 'city', 'state', 'country',
            'website', 'paypal_email', 'payout_methods', 'payout_status',
        ]);

        $org->delete();

        TransactionLog::record('organization.deleted', [
            'subject_type' => Organization::class,
            'subject_id' => $id,
            'actor_id' => $request->user()->id,
            'actor_role' => 'Admin',
            'message' => "Organization deleted: {$before['name']}",
            'before' => $before,
            'after' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Organization deleted successfully',
        ], Response::HTTP_OK);
    }

    /**
     * Update an organization.
     *
     * Every registry field is editable — name, EIN, website, location, PayPal
     * email and payout rails — because admins routinely correct details donors
     * submitted or that the directory had wrong. `normalized_name` is always
     * derived here (never trusted from the client) and both it and the EIN stay
     * unique. Changing the PayPal email resets verification so the new
     * destination is re-confirmed.
     */
    public function update(Request $request, int $id): JsonResponse
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
            // `sometimes` keeps partial updates (payout rails only, email only)
            // working for existing clients.
            'name' => 'sometimes|required|string|max:255',
            'ein' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
            'paypal_email' => 'nullable|email|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'payout_methods' => 'nullable|array',
            'payout_methods.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
                'field_errors' => $validator->errors()->toArray(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Payout rails must reference active, payout-capable payment methods —
        // the registry is the single source of truth (never client free-text).
        if ($request->has('payout_methods') && $error = $this->payoutMethodError($request->input('payout_methods'))) {
            return $this->validationError(['payout_methods' => $error]);
        }

        $normalizedName = null;
        $name = null;
        if ($request->has('name')) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name')));

            if ($name === '') {
                return $this->validationError(['name' => 'The organization name is required.']);
            }

            $normalizedName = Organization::normalizeName($name);

            if (Organization::where('normalized_name', $normalizedName)
                ->where('id', '!=', $org->id)
                ->exists()) {
                return $this->validationError(['name' => 'An organization with this name already exists.']);
            }
        }

        $ein = null;
        if ($request->has('ein')) {
            $ein = $this->normalizeEin($request->input('ein'));

            if ($ein !== null && Organization::where('ein', $ein)
                ->where('id', '!=', $org->id)
                ->exists()) {
                return $this->validationError(['ein' => 'An organization with this EIN already exists.']);
            }
        }

        $tracked = [
            'name', 'normalized_name', 'ein', 'city', 'state', 'country',
            'website', 'paypal_email', 'payout_methods', 'payout_status', 'verified_at',
        ];
        $before = $org->only($tracked);

        $paypalChanged = $request->has('paypal_email')
            && mb_strtolower(trim((string) $request->input('paypal_email') ?? '')) !== mb_strtolower(trim((string) $org->paypal_email ?? ''));

        if ($normalizedName !== null) {
            $org->name = $name;
            $org->normalized_name = $normalizedName;
        }

        if ($request->has('ein')) {
            $org->ein = $ein;
        }

        if ($request->has('website')) {
            $org->website = $request->filled('website')
                ? trim((string) $request->input('website'))
                : null;
        }

        $org->fill($request->only(['city', 'state', 'country']));

        if ($request->has('paypal_email')) {
            $org->paypal_email = $request->filled('paypal_email')
                ? mb_strtolower(trim((string) $request->input('paypal_email')))
                : null;
        }

        if ($request->has('payout_methods')) {
            $org->payout_methods = $this->normalizePayoutMethods($request->input('payout_methods'));
        }

        if ($paypalChanged) {
            $org->payout_status = 'unverified';
            $org->verified_at = null;
        }

        $org->updated_by_id = $request->user()->id;
        $org->save();

        TransactionLog::record('organization.updated', [
            'subject_type' => Organization::class,
            'subject_id' => $org->id,
            'actor_id' => $request->user()->id,
            'actor_role' => 'Admin',
            'message' => "Organization updated: {$org->name}",
            'before' => $before,
            'after' => $org->only($tracked),
        ]);

        if ($paypalChanged) {
            TransactionLog::record('organization.verification_reset', [
                'subject_type' => Organization::class,
                'subject_id' => $org->id,
                'actor_id' => $request->user()->id,
                'actor_role' => 'Admin',
                'message' => "Verification reset for {$org->name} after PayPal email change",
                'before' => ['payout_status' => $before['payout_status'] ?? null],
                'after' => ['payout_status' => 'unverified', 'verified_at' => null],
            ]);
        }

        // A changed destination invalidates verification: the new address has
        // never been confirmed. Changing the email resets payout_status to
        // unverified so admins re-approve the new destination. Manual
        // verification (verify endpoint) superseded the token email flow.
        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully',
            'data' => $org->refresh(),
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

    /**
     * Normalize a submitted payout-method list into a clean, unique,
     * lowercase array of payment-method codes.
     *
     * @param  mixed  $methods
     * @return array<int, string>
     */
    private function normalizePayoutMethods($methods): array
    {
        if (! is_array($methods)) {
            return [];
        }

        $codes = array_filter(array_map(
            fn ($code) => is_string($code) ? strtolower(trim($code)) : null,
            $methods
        ));

        return array_values(array_unique($codes));
    }

    /**
     * Validate that every requested payout method is active and
     * payout-capable in the payment_methods registry.
     *
     * @param  mixed  $methods
     * @return string|null Error message, or null when valid.
     */
    private function payoutMethodError($methods): ?string
    {
        $codes = $this->normalizePayoutMethods($methods);

        if ($codes === []) {
            return 'Select at least one payout method.';
        }

        $valid = PaymentMethod::query()->active()->payout()->pluck('code')
            ->map(fn ($code) => strtolower($code))
            ->all();

        $invalid = array_diff($codes, $valid);

        return $invalid === []
            ? null
            : 'Unknown or inactive payout method: '.implode(', ', $invalid).'.';
    }

    /**
     * Normalize a client EIN the same way the model does (strip separators,
     * uppercase) so uniqueness checks match stored values.
     */
    private function normalizeEin($ein): ?string
    {
        return Organization::normalizeEin($ein);
    }

    /**
     * Count donation formulas that still reference this organization, either
     * by persisted id or (legacy rows) by normalized name.
     */
    private function formulaReferenceCount(Organization $org): int
    {
        $count = 0;

        // JSON containment on an array of objects is not portable across
        // MySQL/SQLite, so scan the decoded rows (chunked to bound memory)
        // and match on persisted id first, normalized name as a fallback for
        // legacy rows that predate organization_id.
        DonationFormula::query()
            ->select(['id', 'formula'])
            ->chunkById(500, function ($formulas) use ($org, &$count) {
                foreach ($formulas as $formula) {
                    foreach ((array) $formula->formula as $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }

                        $entryId = $entry['organization_id'] ?? null;
                        $entryName = $entry['organization'] ?? null;

                        if ((int) $entryId === $org->id
                            || (is_string($entryName)
                                && Organization::normalizeName($entryName) === $org->normalized_name)) {
                            $count++;

                            continue 2;
                        }
                    }
                }
            });

        return $count;
    }

    /**
     * Uniform 422 payload with per-field messages the SPA can map inline.
     *
     * @param  array<string, string|array<int, string>>  $fields
     */
    private function validationError(array $fields): JsonResponse
    {
        $flat = [];
        foreach ($fields as $messages) {
            foreach ((array) $messages as $message) {
                $flat[] = $message;
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $flat,
            'field_errors' => $fields,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
