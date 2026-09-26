<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\CharitySearchService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CharitySearchController extends Controller
{
    /**
     * Autocomplete US tax-exempt organizations by keyword.
     *
     * Identity lookup only (name + EIN + location). Free-text entry remains
     * valid when there is no match, so an empty result is a normal outcome.
     */
    public function search(Request $request, CharitySearchService $service)
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
