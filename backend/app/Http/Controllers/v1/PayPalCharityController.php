<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\PayPalCharityScraper;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayPalCharityController extends Controller
{
    private const FALLBACK_KEYWORDS = [
        'education',
        'health',
        'children',
        'animals',
        'disaster',
        'environment',
        'hunger',
        'women',
        'medical',
        'community',
    ];

    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'keywords' => 'nullable|string|max:255',
            ]);

            $keyword = $request->input('keywords', '');
            $keywordSlug = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($keyword));
            $cacheKey = PayPalCharityScraper::CACHE_PREFIX.$keywordSlug;

            $cachedData = cache()->get($cacheKey);

            if ($cachedData === null && $keyword === '') {
                $allData = [];
                $totalCount = 0;
                $cachedAt = null;
                foreach (self::FALLBACK_KEYWORDS as $fallbackKeyword) {
                    $data = cache()->get(PayPalCharityScraper::CACHE_PREFIX.$fallbackKeyword);
                    if ($data !== null) {
                        $allData = array_merge($allData, $data['data'] ?? []);
                        $totalCount = count($allData);
                        $cachedAt = $data['cached_at'];
                    }
                }
                if ($allData !== []) {
                    $cachedData = [
                        'data' => $allData,
                        'total' => $totalCount,
                        'cached_at' => $cachedAt,
                    ];
                }
            }

            if ($cachedData === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'No cached charities found. Please try again later.',
                    'data' => [],
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return response()->json([
                'success' => true,
                'message' => 'Charities retrieved successfully',
                'data' => $cachedData['data'],
                'meta' => [
                    'total' => $cachedData['total'],
                    'cached' => true,
                    'cached_at' => $cachedData['cached_at'],
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Please try again few seconds later',
                'devMessage' => $e->getMessage(),
                'data' => [],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
