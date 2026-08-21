<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\PageContent;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PageController extends Controller
{
    public function show(string $page): JsonResponse
    {
        try {
            $content = PageContent::where('page', $page)->first();

            if (! $content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Page not found.',
                    'errors' => ['Page not found.'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'page' => $content->page,
                    'content' => $content->content,
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load page.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
