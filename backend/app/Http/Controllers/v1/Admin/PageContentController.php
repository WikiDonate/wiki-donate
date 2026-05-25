<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageContent;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class PageContentController extends Controller
{
    public function show(string $page): JsonResponse
    {
        try {
            $content = PageContent::where('page', $page)->first();

            if (! $content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Page content not found.',
                    'errors' => ['Page content not found.'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'page' => $content->page,
                    'content' => $content->content,
                    'updatedAt' => $content->updated_at->format('d M, Y H:i'),
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch page content.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function update(Request $request, string $page): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'content' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $content = PageContent::updateOrCreate(
                ['page' => $page],
                ['content' => $request->content]
            );

            Cache::store('file')->forget('admin_dashboard_data');

            return response()->json([
                'success' => true,
                'message' => 'Page content updated successfully.',
                'data' => [
                    'page' => $content->page,
                    'content' => $content->content,
                    'updatedAt' => $content->updated_at->format('d M, Y H:i'),
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update page content.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
