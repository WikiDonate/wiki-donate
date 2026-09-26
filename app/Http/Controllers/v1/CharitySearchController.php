<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\Charity\VerifiedCharitySearchService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CharitySearchController extends Controller
{
    /**
     * Autocomplete verified US charities (valid EIN only) by keyword.
     *
     * Identity lookup for donation formulas. Only organizations carrying a
     * valid EIN are returned, so every selectable row identifies a real,
     * donatable charity.
     */
    public function search(Request $request, VerifiedCharitySearchService $service)
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return response()->json([
                'success' => true,
                'message' => 'Query too short',
                'data' => [],
            ], Response::HTTP_OK);
        }

        return response()->json([
            'success' => true,
            'message' => 'Charities retrieved successfully',
            'data' => $service->search($query),
        ], Response::HTTP_OK);
    }
}
