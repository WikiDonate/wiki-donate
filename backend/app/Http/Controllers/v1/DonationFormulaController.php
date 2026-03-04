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
     * Display the donation formula for the specified article.
     */
    public function show($slug)
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

            $formula = DonationFormula::where('article_id', $article->id)
                ->where('user_id', Auth::id())
                ->first();

            if (! $formula) {
                return response()->json([
                    'success' => false,
                    'message' => 'Donation formula not found',
                    'data' => null,
                ], Response::HTTP_OK);
            }

            return response()->json([
                'success' => true,
                'message' => 'Donation formula retrieved successfully',
                'data' => $formula->formula,
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
     * Store or update a donation formula for an article.
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

            $formula = DonationFormula::updateOrCreate(
                [
                    'article_id' => $article->id,
                    'user_id' => Auth::id(),
                ],
                [
                    'formula' => $request->formula,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Donation formula saved successfully',
                'data' => $formula->formula,
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
