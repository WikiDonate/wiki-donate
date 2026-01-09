<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\ArticleResource;
use App\Http\Resources\v1\RevisionResource;
use App\Http\Resources\v1\TalkResource;
use App\Models\Article;
use App\Models\Talk;
use App\Models\TalkRevision;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TalkController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/v1/talk/{slug}",
     *     summary="Get talk by slug",
     *     tags={"Talk"},
     *
     *     @OA\Parameter(
     *         description="Slug of the article",
     *         in="path",
     *         name="slug",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="string"
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

            $talk = Talk::where('article_id', $article->id)->first();
            if (empty($talk)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Talk is not found',
                    'errors' => ['Talk is not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'success' => true,
                'message' => 'Talk found successfully',
                'data' => new TalkResource($talk),
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
     *     path="/api/v1/talk/save",
     *     summary="Save talk",
     *     tags={"Talk"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="articleUuid", type="string", example="ad1f4c0c-0b6f-4a5f-8f1f-9b3b6a5f5d9d"),
     *             @OA\Property(property="title", type="string", example="Title of talk"),
     *             @OA\Property(property="content", type="string", example="<p>Content of talk</p>"),
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
     *         description="Exceptions error",
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
     *     ),
     * )
     */
    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'articleUuid' => 'required|exists:articles,uuid',
            'title' => 'required|string',
            'content' => 'required|string',
            'type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $content = $request->content;

            /**
             * Signature replacement:
             * Replace only exact occurrences of '~~~~' with
             * [[User:username|username]] HH:mm, DD Month YYYY (UTC)
             */
            if (Str::contains($content, '~~~~')) {
                $user = $request->user();

                // Replace only exact matches of 4 tildes, not 3 or 5+
                $content = preg_replace_callback('/(?<!~)~~~~(?!~)/', function () use ($user) {
                    $utcTime = now('UTC')->format('H:i, j F Y (\\U\\T\\C)');

                    return "[[User:{$user->username}|{$user->username}]] {$utcTime}";
                }, $content);
            }

            // Parse HTML sections
            $sections = parseHtmlSection($content);
            if (empty($sections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['No sections found'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Add uuid + metadata to each section
            $sections = collect($sections)->map(function ($section) use ($request) {
                return [
                    'uuid' => Str::uuid()->toString(),
                    'title' => $section['title'] ?? null,
                    'content' => $section['content'] ?? null,
                    'edited_by' => $request->user()->id,
                    'edited_at' => now()->toDateTimeString(),
                ];
            })->toArray();

            // Check duplicate slug
            $duplicateSlug = Talk::where('slug', Str::slug($request->title))->first();
            if ($duplicateSlug) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate slug found',
                    'errors' => ['Duplicate slug found'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $message = 'The talk has been created successfully';

            $talkParams = [
                'user_id' => $request->user()->id,
                'article_id' => Article::where('uuid', $request->articleUuid)->first()->id,
                'title' => $request->title,
                'slug' => Str::slug($request->title),
                'sections' => json_encode($sections),
            ];

            if (! empty($request->type) && $request->type === 'userPage') {
                $message = 'The user page talk has been created successfully';
                $talkParams['slug'] = $request->title;
                $talkParams['type'] = 'user page';
            }

            $talk = Talk::create($talkParams);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => new TalkResource($talk),
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
     *     path="/api/v1/talks/update/{slug}",
     *     summary="Update a talk by slug",
     *     tags={"Talk"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         description="Slug of the talk",
     *         in="path",
     *         name="slug",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="string"
     *         )
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"uuid", "title", "content"},
     *
     *             @OA\Property(property="uuid", type="string", example="d6f4c2d9-5b34-4a0a-8a3e-1c5a4b3f9f63"),
     *             @OA\Property(property="title", type="string", example="Updated talk title"),
     *             @OA\Property(property="content", type="string", example="<p>Updated content of the talk</p>")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successfully updated the talk",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Talk updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Error"),
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
     *         response=404,
     *         description="Talk not found",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Not found"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(type="string")
     *             )
     *         )
     *     )
     * )
     */
    public function update(Request $request, $slug)
    {
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|exists:talks,uuid',
            'title' => 'required|string',
            'content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'errors' => $validator->errors()->all(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $existsTalk = Talk::where('uuid', $request->uuid)->first();
            if (! $existsTalk) {
                return response()->json([
                    'success' => false,
                    'message' => 'Talk not found',
                    'errors' => ['Talk not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            $oldSections = json_decode($existsTalk->sections, true);

            $content = $request->content;

            /**
             * Signature replacement:
             * Replace only exact occurrences of '~~~~' with
             * [[User:username|username]] HH:mm, DD Month YYYY (UTC)
             */
            if (Str::contains($content, '~~~~')) {
                $user = $request->user();

                // Replace only exact matches of 4 tildes, not 3 or 5+
                $content = preg_replace_callback('/(?<!~)~~~~(?!~)/', function () use ($user) {
                    $utcTime = now('UTC')->format('H:i, j F Y (\\U\\T\\C)');

                    return "[[User:{$user->username}|{$user->username}]] {$utcTime}";
                }, $content);
            }

            // Parse fresh sections from HTML after signature handling
            $parsedSections = parseHtmlSection($content);
            if (empty($parsedSections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['No sections found'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Create a map of old sections by content signature for matching
            $oldSectionsMap = [];
            foreach ($oldSections as $oldSection) {
                $signature = md5(($oldSection['title'] ?? '').'|'.($oldSection['content'] ?? ''));
                $oldSectionsMap[$signature] = $oldSection;
            }

            $newSections = [];
            foreach ($parsedSections as $section) {
                $signature = md5(($section['title'] ?? '').'|'.($section['content'] ?? ''));

                // Check if this exact section existed before
                if (isset($oldSectionsMap[$signature])) {
                    // Section is untouched - keep everything from old section
                    $old = $oldSectionsMap[$signature];
                    $newSections[] = [
                        'uuid' => $old['uuid'],
                        'title' => $old['title'],
                        'content' => $old['content'],
                        'edited_by' => $old['edited_by'] ?? $request->user()->id,
                        'edited_at' => $old['edited_at'] ?? now()->toDateTimeString(),
                    ];

                    // Remove from map so it can't be matched again
                    unset($oldSectionsMap[$signature]);
                } else {
                    // New or modified section - assign new UUID and update metadata
                    $newSections[] = [
                        'uuid' => Str::uuid()->toString(),
                        'title' => $section['title'] ?? null,
                        'content' => $section['content'] ?? null,
                        'edited_by' => $request->user()->id,
                        'edited_at' => now()->toDateTimeString(),
                    ];
                }
            }

            // No changes check
            if (json_encode($oldSections) === json_encode($newSections)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['There is no change in article'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Update talk
            $existsTalk->update([
                'title' => $request->title,
                'sections' => json_encode($newSections),
            ]);

            // Save revision
            $latestVersionNumber = TalkRevision::where('talk_id', $existsTalk->id)->max('version') ?? 0;

            TalkRevision::create([
                'talk_id' => $existsTalk->id,
                'user_id' => $request->user()->id,
                'version' => $latestVersionNumber + 1,
                'old_content' => json_encode($oldSections),
                'new_content' => json_encode($newSections),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'The talk has been updated successfully',
                'data' => new TalkResource(Talk::find($existsTalk->id)),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

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
     *     path="/api/v1/talks/{slug}/history",
     *     summary="Get all revision of talk by slug",
     *     tags={"Talk"},
     *
     *     @OA\Parameter(
     *         description="Slug of talk",
     *         in="path",
     *         name="slug",
     *         required=true,
     *         example="slug-of-talk"
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
     *                 type="object",
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
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Exception",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Exception message"),
     *             @OA\Property(
     *                 property="errors",
     *                 type="array",
     *
     *                 @OA\Items(
     *                     type="string"
     *                 )
     *             )
     *         ),
     *     ),
     * )
     */
    public function history($slug)
    {
        try {
            $talk = Talk::where('slug', $slug)->first();
            if (empty($talk)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Talk not found',
                    'errors' => ['Talk not found'],
                ], Response::HTTP_NOT_FOUND);
            }

            $versions = TalkRevision::where('talk_id', $talk->id)->orderBy('version', 'desc')->get();
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
}
