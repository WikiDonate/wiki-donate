<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\NotificationResource;
use App\Models\Notification;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * @OA\Get(
     *      path="/api/v1/user/notifications",
     *      operationId="getNotifications",
     *      tags={"User"},
     *      security={{"sanctum":{}}},
     *      description="Get the user's notification data",
     *
     *      @OA\Response(
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
     *   )
     */
    public function show(Request $request)
    {
        try {
            $notifications = Notification::where('user_id', $request->user()->id)->first();
            if (! $notifications) {
                return response()->json([
                    'success' => true,
                    'message' => 'Notification data is not found',
                    'data' => [],
                ], Response::HTTP_OK);
            }

            return response()->json([
                'success' => true,
                'message' => 'Notification data found successfully',
                'data' => new NotificationResource($notifications),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception Error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * @OA\Post(
     *      path="/api/v1/user/notifications",
     *      operationId="updateNotifications",
     *      tags={"User"},
     *      summary="Update user notification preferences",
     *      security={{"sanctum":{}}},
     *
     *      @OA\RequestBody(
     *          required=true,
     *
     *          @OA\JsonContent(
     *              type="object",
     *
     *              @OA\Property(property="editTalkPage", type="integer", example=0, description="Edit talk page"),
     *              @OA\Property(property="editUserPage", type="integer", example=0, description="Edit user page"),
     *              @OA\Property(property="pageReview", type="integer", example=0, description="Page review"),
     *              @OA\Property(property="emailFromOther", type="integer", example=0, description="Email from other"),
     *              @OA\Property(property="successfulMention", type="integer", example=0, description="Successful mention"),
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=200,
     *          description="Success",
     *
     *          @OA\JsonContent(
     *              type="object",
     *
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Success message"),
     *              @OA\Property(property="data", type="object"),
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=422,
     *          description="Validation Error",
     *
     *          @OA\JsonContent(
     *              type="object",
     *
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Validation Error"),
     *              @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Error message")),
     *          )
     *      ),
     *
     *      @OA\Response(
     *          response=500,
     *          description="Exception Error",
     *
     *          @OA\JsonContent(
     *              type="object",
     *
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Exception error"),
     *              @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Error message")),
     *          )
     *      ),
     * )
     */
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'editTalkPage' => 'required|in:0,1',
                'editUserPage' => 'required|in:0,1',
                'pageReview' => 'required|in:0,1',
                'emailFromOther' => 'required|in:0,1',
                'successfulMention' => 'required|in:0,1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation Error',
                    'errors' => $validator->errors()->all(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $notifications = Notification::updateOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'edit_talk_page' => $request->editTalkPage,
                    'edit_user_page' => $request->editUserPage,
                    'page_review' => $request->pageReview,
                    'email_from_other' => $request->emailFromOther,
                    'successful_mention' => $request->successfulMention,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Notification settings update successfully',
                'data' => new NotificationResource($notifications),
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exception Error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
