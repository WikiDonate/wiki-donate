<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\ArticleResource;
use App\Http\Resources\v1\RevisionResource;
use App\Models\Article;
use App\Models\Revision;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ArticleController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/articles",
     *     summary="Get all articles",
     *     tags={"Articles"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Success response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object"
     *                 )
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Error response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=417,
     *         description="Exception error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Exception message"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     * )
     */
    public function index()
    {
        try {
            $articles = Article::get();
            if ($articles->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No articles found',
                    'errors' => ['No articles found'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'message' => 'Articles found successfully',
                'data' => ArticleResource::collection($articles),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/articles/{slug}",
     *     tags={"Articles"},
     *     summary="Show article by slug",
     *
     *     @OA\Parameter(
     *         description="Slug of article",
     *         in="path",
     *         name="slug",
     *         required=true,
     *         example="slug-of-article"
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object"
     * ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Not found",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Not found"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Exceptions error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Exceptions error"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     * )
     */
    public function show($slug)
    {
        try {
            $article = Article::where('slug', $slug)->first();
            if (empty($article)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found',
                    'errors' => ['Article not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'message' => 'Article found successfully',
                'data' => new ArticleResource($article),
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }

    }

    /**
     * @OA\Post(
     *     path="/api/v1/articles",
     *     tags={"Articles"},
     *     summary="Save article",
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Article object that needs to be added",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="title", type="string", example="Title of article"),
     *             @OA\Property(property="content", type="string", example="<p>Content of article</p>"),
     *             @OA\Property(
     *                 property="type",
     *                 type="string",
     *                 example="article",
     *                 enum={"article", "userPage"},
     *                 nullable=true
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Article saved successfully",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object"
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation Exceptions",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="The given data was invalid."
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(type="string")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Internal Server Error"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(type="string")
     *             )
     *         )
     *     ),
     * )
     */
    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string',
            'content' => 'required|string',
            'type' => 'nullable|string',
            'accessType' => 'nullable|in:public,private',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Parse HTML sections
            $sections = parseHtmlSection($request->content);
            if (empty($sections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['No sections found'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Check duplicate slug from articles table
            $duplicateSlug = Article::where('slug', Str::slug($request->title))->first();
            if ($duplicateSlug) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate slug found',
                    'errors' => ['Duplicate slug found'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $message = 'The article has been created successfully';

            // Determine default access type based on article type
            $defaultAccessType = (empty($request->type) || $request->type === 'article') ? 'public' : 'private';

            // Create article
            $articleParams = [
                'user_id' => $request->user()->id,
                // Prefix "Formula: " only when access type is private
                'title' => ($request->input('accessType') === 'private')
                    ? (Str::startsWith($request->title, 'Formula:')
                        ? $request->title
                        : 'Formula:'.$request->title)
                    : $request->title,
                'slug' => Str::slug($request->title),
                'sections' => json_encode($sections),
                'access_type' => $request->input('accessType', $defaultAccessType),
            ];

            if (! empty($request->type) && $request->type == 'userPage') {
                $message = 'The user page has been created successfully';
                $articleParams['slug'] = $request->title;
                $articleParams['type'] = 'user page';
            }

            $article = Article::create($articleParams);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => new ArticleResource($article),
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/articles/{slug}",
     *     tags={"Articles"},
     *     summary="Update article by slug",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         description="Slug of article",
     *         in="path",
     *         name="slug",
     *         required=true,
     *         example="slug-of-article"
     *     ),
     *
     *     @OA\RequestBody(
     *         description="Article to be updated",
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="title",
     *                 type="string",
     *                 description="Title of the article",
     *                 example="Title of article"
     *             ),
     *             @OA\Property(
     *                 property="content",
     *                 type="string",
     *                 description="Content of the article",
     *                 example="Content of article"
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object"
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Not found",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Not found"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation Exceptions",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="The given data was invalid."
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(type="string")
     *             )
     *         )
     *     ),
     * )
     */
    public function update(Request $request, $slug)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string',
            'content' => 'required|string',
            'accessType' => 'nullable|in:public,private',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // Clean title
            $cleanTitle = trim(str_replace('Formula:', '', $request->title));

            // Prefer using the slug from route if available
            $existsArticle = Article::with('user')
                ->where('slug', $slug ?? Str::slug($cleanTitle))
                ->first();

            if (! $existsArticle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found',
                    'errors' => ['Article not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            // Only admin can edit the admin's article
            if ($existsArticle->user->hasRole(['Admin'])) {
                if (! Auth::user()->hasRole(['Admin'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Permission denied',
                        'errors' => ['You have no permission to edit this article. It can admin only'],
                    ], Response::HTTP_NOT_FOUND);
                }
            }

            $oldSections = json_decode($existsArticle->sections, true);

            // Parse HTML sections
            $sections = parseHtmlSection($request->content);
            if (empty($sections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['No sections found'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Keep original behavior: block when sections have not changed,
            // except allow update if accessType is provided and actually changed
            $noSectionsChange = json_encode($oldSections) === json_encode($sections);
            $accessTypeProvided = $request->filled('accessType');
            $accessTypeChanged = $accessTypeProvided && ($existsArticle->access_type !== $request->input('accessType'));

            if ($noSectionsChange && ! $accessTypeChanged) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['There is no change in article'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Build update payload; only change access_type if explicitly provided
            $updatePayload = [
                'sections' => json_encode($sections),
            ];

            // Handle access type and title logic
            if ($request->filled('accessType')) {
                $newAccessType = $request->input('accessType');
                $updatePayload['access_type'] = $newAccessType;

                // Update title based on access type change
                if ($newAccessType === 'private') {
                    // Add "Formula:" if missing
                    $updatePayload['title'] = Str::startsWith($request->title, 'Formula:')
                        ? $request->title
                        : 'Formula:'.$request->title;
                } elseif ($newAccessType === 'public') {
                    // Remove "Formula:" if present
                    $updatePayload['title'] = preg_replace('/^Formula:/', '', $request->title);
                } else {
                    $updatePayload['title'] = $request->title;
                }
            } else {
                $updatePayload['title'] = $request->title;
            }

            // Update article
            $existsArticle->update($updatePayload);

            $latestVersionNumber = Revision::where('article_id', $existsArticle->id)->max('version') ?? 0;

            // Create versions
            Revision::create([
                'article_id' => $existsArticle->id,
                'user_id' => $request->user()->id,
                'version' => $latestVersionNumber + 1,
                'old_content' => json_encode($oldSections),
                'new_content' => json_encode($sections),

            ]);

            return response()->json([
                'success' => true,
                'message' => 'The article updated successfully',
                'data' => new ArticleResource(Article::find($existsArticle->id)),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/search",
     *     tags={"Articles"},
     *     summary="Search article",
     *     description="Search article by query",
     *
     *     @OA\Parameter(
     *         name="query",
     *         in="query",
     *         description="Query for search article",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="string",
     *             maxLength=255
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object"
     *                 )
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation Exceptions",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Error message"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Get the query parameter
        $query = $request->input('query');

        // Search for articles by title
        $articles = Article::where('title', 'LIKE', '%'.$query.'%')
            ->where('type', 'article')
            ->orderBy('title')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Articles found successfully',
            'data' => ArticleResource::collection($articles),
        ], Response::HTTP_OK);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/articles/{slug}/history",
     *     summary="Get all revision of article by slug",
     *     tags={"Articles"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         description="Slug of article",
     *         in="path",
     *         name="slug",
     *         required=true,
     *         example="slug-of-article"
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Success response",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Success message"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="object"
     *                 )
     *             ),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Not found",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Not found"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Exceptions error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=false
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Exceptions error"
     *             ),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function history($slug)
    {
        try {
            $article = Article::where('slug', $slug)->first();
            if (empty($article)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Article not found',
                    'errors' => ['Article not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            $versions = Revision::where('article_id', $article->id)->orderBy('version', 'desc')->get();
            if ($versions->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'History not found',
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'message' => 'History found successfully',
                'data' => RevisionResource::collection($versions),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }

    }

    /**
     * Get paginated articles for the logged-in user (type: article)
     */
    public function myArticles(Request $request)
    {
        try {
            $perPage = (int) ($request->input('per_page') ?? 10);
            $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 10;

            $articles = Article::where('user_id', Auth::id())
                ->where(function ($q) {
                    $q->whereNull('type')->orWhere('type', 'article');
                })
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage);

            if ($articles->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No articles found',
                    'data' => [],
                    'meta' => [
                        'currentPage' => $articles->currentPage(),
                        'perPage' => $articles->perPage(),
                        'total' => $articles->total(),
                        'lastPage' => $articles->lastPage(),
                    ],
                ], Response::HTTP_OK);
            }

            return response()->json([
                'success' => true,
                'message' => 'Articles found successfully',
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
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
