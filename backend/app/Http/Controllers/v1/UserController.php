<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\UserResource;
use App\Mail\CongratulationMail;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/register",
     *     summary="Register a new user",
     *     tags={"User"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"username", "password", "confirmPassword", "token"},
     *
     *             @OA\Property(property="username", type="string", example="john_doe"),
     *             @OA\Property(property="password", type="string", example="strongPassword123"),
     *             @OA\Property(property="confirmPassword", type="string", example="strongPassword123"),
     *             @OA\Property(property="email", type="string", format="email", example="john_doe@example.com"),
     *             @OA\Property(property="token", type="string", example="reCaptchaToken")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful registration",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User registered successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *             ),
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation Error"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *         @OA\Response(
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
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required|unique:users',
                'password' => 'required',
                'confirmPassword' => 'required|same:password',
                'email' => 'nullable|email|unique:users',
                'token' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation Error',
                    'errors' => $validator->errors()->all(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (! verifyRecaptcha($request->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'reCAPTCHA verification failed',
                    'errors' => 'reCAPTCHA verification failed',
                ], Response::HTTP_FORBIDDEN);
            }

            if (! empty($user->email)) {
                $disposable = Http::get("https://open.kickbox.com/v1/disposable/$email");
                if ($disposable->json()['disposable'] ?? false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Disposable email address',
                        'errors' => 'Disposable email addresses are not allowed.',
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            // Create user
            $user = User::create([
                'username' => $request->username,
                'email' => $request->email ?? null,
                'password' => Hash::make($request->password),
            ]);

            $user->assignRole('Editor');

            // Send email
            if (! empty($user->email)) {
                Mail::to($user->email)->queue(new CongratulationMail($user));
            }

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => new UserResource($user),
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
     * @OA\Post(
     *     path="/api/v1/changePassword",
     *     operationId="changeUserPassword",
     *     tags={"User"},
     *     summary="Change user password",
     *     description="Allows a user to change their password",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"newPassword","confirmPassword"},
     *
     *             @OA\Property(property="newPassword", type="string", example="newStrongPassword123"),
     *             @OA\Property(property="confirmPassword", type="string", example="newStrongPassword123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Password changed successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Password changed successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Error"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=417,
     *         description="Exceptions error",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Error"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function changePassword(Request $request)
    {
        try {
            // Validate input fields
            $validator = Validator::make($request->all(), [
                'newPassword' => 'required|min:8',
                'confirmPassword' => 'required|same:newPassword', // Ensure confirm password matches new password
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => $validator->errors()->all(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Retrieve the authenticated user from the token
            $user = $request->user();

            // Check if the user was successfully retrieved
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Update to new password
            $user->password = Hash::make($request->newPassword);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Change password successfully',
                'data' => new UserResource($user),
            ], Response::HTTP_CREATED);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Exceptions error',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }
}
