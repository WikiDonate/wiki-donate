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
            'formula' => 'required|array',
            'formula.*.organization' => 'required|string',
            'formula.*.percentage' => 'required|numeric|min:0|max:100',
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
                'formula' => $request->formula,
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
            'formula' => 'required|array',
            'formula.*.organization' => 'required|string',
            'formula.*.percentage' => 'required|numeric|min:0|max:100',
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
                'formula' => $request->formula,
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
