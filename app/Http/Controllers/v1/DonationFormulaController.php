<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\DonationFormula;
use App\Models\Organization;
use App\Models\TransactionLog;
use App\Services\Organization\OrganizationRegistryService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class DonationFormulaController extends Controller
{
    /**
     * Display a listing of donation formulas for a specified article.
     */
    public function index($slug)
    {
        try {
            $article = Article::where('slug', $slug)->first();

            if (! $article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found',
                    'errors' => ['Article not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            // Return all formulas for this article
            $formulas = DonationFormula::with('user:id,username')
                ->where('article_id', $article->id)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Donation formulas retrieved successfully',
                'data' => $formulas,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Display the specified donation formula.
     */
    public function show($uuid)
    {
        try {
            $formula = DonationFormula::where('uuid', $uuid)->first();

            if (! $formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'Donation formula not found',
                    'errors' => ['Donation formula not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'message' => 'Donation formula retrieved successfully',
                'data' => $formula,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Cross-row consistency for organization identity.
     *
     * The registry resolves rows by EIN, then name, then ID — so a wrong
     * combination submitted directly via API would silently merge or create
     * the wrong organization record. Enforce here:
     *  - organization names are bounded (org table is varchar 255);
     *  - a provided EIN must be 9 digits (real US EIN form, dashes allowed);
     *  - organization_id + ein together must belong to the same record.
     *
     * Returns an error response when invalid, null when rows are consistent.
     */
    private function validateOrganizationIdentity(array $rows)
    {
        foreach (array_values($rows) as $i => $row) {
            $rowNo = $i + 1;

            if (mb_strlen((string) ($row['organization'] ?? '')) > 255) {
                return $this->identityError("Row {$rowNo}: organization name is too long.");
            }

            $ein = $this->normalizeEin($row['ein'] ?? null);
            if (array_key_exists('ein', $row) && $row['ein'] !== null && $row['ein'] !== '' && $ein === null) {
                return $this->identityError("Row {$rowNo}: EIN must be 9 digits (e.g. 53-0196605).");
            }

            if (! empty($row['organization_id']) && $ein !== null) {
                $org = Organization::find($row['organization_id']);
                if ($org && $this->normalizeEin($org->ein) !== null && $this->normalizeEin($org->ein) !== $ein) {
                    return $this->identityError("Row {$rowNo}: EIN does not belong to the given organization.");
                }
            }
        }

        return null;
    }

    /**
     * Canonical EIN form: digits only, uppercased — mirrors
     * OrganizationRegistryService so both sides compare identically.
     */
    private function normalizeEin(mixed $ein): ?string
    {
        if ($ein === null || $ein === '') {
            return null;
        }

        $clean = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $ein));

        return preg_match('/^[0-9]{9}$/', $clean) ? $clean : null;
    }

    private function identityError(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => [$message],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Store a new donation formula for an article.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'article_slug' => 'required|string',
            'name' => 'required|string|max:255',
            'formula' => 'required|array',
            'formula.*.organization' => 'required|string|max:255',
            'formula.*.organization_id' => 'nullable|integer|exists:organizations,id',
            'formula.*.ein' => 'nullable|string|max:50',
            'formula.*.website' => 'nullable|string|max:500',
            'formula.*.city' => 'nullable|string|max:100',
            'formula.*.state' => 'nullable|string|max:10',
            'formula.*.percentage' => 'required|numeric|min:0|max:100',
            'details' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($error = $this->validateOrganizationIdentity($request->input('formula', []))) {
            return $error;
        }

        try {
            $article = Article::where('slug', $request->article_slug)->first();

            if (! $article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found',
                    'errors' => ['Article not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            // Reject duplicate name for the same user on the same article
            $duplicate = DonationFormula::where('article_id', $article->id)
                ->where('user_id', Auth::id())
                ->whereRaw('LOWER(name) = ?', [strtolower(trim($request->name))])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'A formula with this name already exists for this article.',
                    'errors' => ['A formula with this name already exists for this article.'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Calculate total percentage
            $totalPercentage = array_reduce($request->formula, function ($sum, $item) {
                return $sum + $item['percentage'];
            }, 0);

            if (abs($totalPercentage - 100) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total percentage must be 100%',
                    'errors' => ['Total percentage must be 100%'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Upsert organizations and persist organization_id back into rows.
            $formulaRows = OrganizationRegistryService::upsertFromFormulaRows(
                $request->formula,
                Auth::id(),
            );

            // Create new formula (not updateOrCreate anymore)
            $formula = DonationFormula::create([
                'article_id' => $article->id,
                'user_id' => Auth::id(),
                'name' => $request->name,
                'formula' => $formulaRows,
                'details' => $request->details ?? null,
            ]);

            TransactionLog::record('formula.created', [
                'subject_type' => DonationFormula::class,
                'subject_id' => $formula->id,
                'actor_id' => Auth::id(),
                'actor_role' => 'Editor',
                'message' => 'Donation formula created: '.$request->name,
                'after' => [
                    'name' => $formula->name,
                    'article_slug' => $article->slug,
                    'organizations' => collect($formulaRows)->map(fn ($r) => [
                        'organization_id' => $r['organization_id'],
                        'name' => $r['organization'],
                    ])->all(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Donation formula created successfully',
                'data' => $formula,
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Update a donation formula.
     */
    public function update(Request $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'formula' => 'required|array',
            'formula.*.organization' => 'required|string|max:255',
            'formula.*.organization_id' => 'nullable|integer|exists:organizations,id',
            'formula.*.ein' => 'nullable|string|max:50',
            'formula.*.website' => 'nullable|string|max:500',
            'formula.*.city' => 'nullable|string|max:100',
            'formula.*.state' => 'nullable|string|max:10',
            'formula.*.percentage' => 'required|numeric|min:0|max:100',
            'details' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($error = $this->validateOrganizationIdentity($request->input('formula', []))) {
            return $error;
        }

        try {
            $formula = DonationFormula::where('uuid', $uuid)->first();

            if (! $formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'Donation formula not found',
                    'errors' => ['Donation formula not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            // Authorization check: Only creator can edit
            if ($formula->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors' => ['You can only edit your own formulas.'],
                ], Response::HTTP_FORBIDDEN);
            }

            // Immutability check removed: formulas may now be updated even
            // when donations reference them (edits are flagged via is_edited).

            // Reject duplicate name for the same user on the same article
            $duplicate = DonationFormula::where('article_id', $formula->article_id)
                ->where('user_id', Auth::id())
                ->where('id', '!=', $formula->id)
                ->whereRaw('LOWER(name) = ?', [strtolower(trim($request->name))])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'A formula with this name already exists for this article.',
                    'errors' => ['A formula with this name already exists for this article.'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Calculate total percentage
            $totalPercentage = array_reduce($request->formula, function ($sum, $item) {
                return $sum + $item['percentage'];
            }, 0);

            if (abs($totalPercentage - 100) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total percentage must be 100%',
                    'errors' => ['Total percentage must be 100%'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Track edits that affect donations already paid: mark as edited
            // for transparency in donation details.
            $hadCompletedDonation = $formula->hasCompletedDonation();

            // Upsert organizations and persist organization_id back into rows.
            $formulaRows = OrganizationRegistryService::upsertFromFormulaRows(
                $request->formula,
                Auth::id(),
            );

            $beforeFormula = $formula->formula;
            $formula->update([
                'name' => $request->name,
                'formula' => $formulaRows,
                'details' => $request->details ?? null,
            ]);

            TransactionLog::record('formula.updated', [
                'subject_type' => DonationFormula::class,
                'subject_id' => $formula->id,
                'actor_id' => Auth::id(),
                'actor_role' => 'Editor',
                'message' => 'Donation formula updated: '.$request->name,
                'before' => ['formula' => $beforeFormula],
                'after' => [
                    'name' => $formula->name,
                    'formula' => $formulaRows,
                ],
            ]);

            if ($hadCompletedDonation) {
                $formula->forceFill([
                    'is_edited' => true,
                    'edited_at' => now(),
                ])->save();
                $formula->refresh();
            }

            return response()->json([
                'success' => true,
                'message' => 'Donation formula updated successfully',
                'data' => $formula,
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * Delete a donation formula.
     */
    public function destroy($uuid)
    {
        try {
            $formula = DonationFormula::where('uuid', $uuid)->first();

            if (! $formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'Donation formula not found',
                    'errors' => ['Donation formula not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            // Authorization check: Only creator can delete
            if ($formula->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                    'errors' => ['You can only delete your own formulas.'],
                ], Response::HTTP_FORBIDDEN);
            }

            // Soft delete so donation_formula_id references remain valid for
            // history; deleted formulas no longer appear in public lists.
            $formula->delete();

            return response()->json([
                'success' => true,
                'message' => 'Donation formula deleted successfully',
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
