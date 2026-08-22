<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\DonationFormula;
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
     * Store a new donation formula for an article.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'article_slug' => 'required|string',
            'name' => 'required|string|max:255',
            'formula' => 'required|array',
            'formula.*.organization' => 'required|string',
            'formula.*.organization_id' => 'nullable|integer',
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

            // Create new formula (not updateOrCreate anymore)
            $formula = DonationFormula::create([
                'article_id' => $article->id,
                'user_id' => Auth::id(),
                'name' => $request->name,
                'formula' => $request->formula,
                'details' => $request->details ?? null,
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
            'formula.*.organization' => 'required|string',
            'formula.*.organization_id' => 'nullable|integer',
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

            // Immutability: formulas with linked donations cannot be edited
            if ($formula->donations()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot edit formula with existing donations',
                    'errors' => ['This formula cannot be edited because donations already reference it.'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

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

            $formula->update([
                'name' => $request->name,
                'formula' => $request->formula,
                'details' => $request->details ?? null,
            ]);

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

            // Immutability: formulas with linked donations cannot be deleted
            if ($formula->donations()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete formula with existing donations',
                    'errors' => ['This formula cannot be deleted because donations already reference it.'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

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
