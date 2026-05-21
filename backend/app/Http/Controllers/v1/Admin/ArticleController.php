<?php

namespace App\Http\Controllers\v1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\ArticleResource;
use App\Models\Article;
use App\Models\Revision;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) ($request->input('per_page') ?? 15);
            $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 15;

            $query = Article::with('user');

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($uq) use ($search) {
                            $uq->where('username', 'like', "%{$search}%");
                        });
                });
            }

            if ($type = $request->input('type')) {
                if ($type === 'article') {
                    $query->where(function ($q) {
                        $q->whereNull('type')->orWhere('type', 'article');
                    });
                } else {
                    $query->where('type', $type);
                }
            }

            if ($accessType = $request->input('access_type')) {
                $query->where('access_type', $accessType);
            }

            $articles = $query->orderBy('updated_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => ArticleResource::collection($articles->items()),
                'meta' => [
                    'currentPage' => $articles->currentPage(),
                    'perPage' => $articles->perPage(),
                    'total' => $articles->total(),
                    'lastPage' => $articles->lastPage(),
                ],
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch articles.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function show(string $slug): JsonResponse
    {
        try {
            $article = Article::with('user')->where('slug', $slug)->first();

            if (! $article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found.',
                    'errors' => ['Article not found.'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'data' => new ArticleResource($article),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch article.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function destroy(string $slug): JsonResponse
    {
        try {
            $article = Article::where('slug', $slug)->first();

            if (! $article) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found.',
                    'errors' => ['Article not found.'],
                ], Response::HTTP_NOT_FOUND);
            }

            Revision::where('article_id', $article->id)->delete();
            $article->delete();

            Cache::store('file')->forget('admin_dashboard_data');

            return response()->json([
                'success' => true,
                'message' => 'Article deleted successfully.',
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete article.',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
