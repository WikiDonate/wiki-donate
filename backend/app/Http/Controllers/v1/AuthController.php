<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\UserResource;
use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/login",
     *     operationId="loginUser",
     *     tags={"Auth"},
     *     summary="Login a user",
     *     description="Authenticates a user and returns a token",
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"username","password"},
     *
     *             @OA\Property(property="username", type="string", example="john_doe"),
     *             @OA\Property(property="password", type="string", example="strongPassword123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful login",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User logged successfully"),
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
     *             @OA\Property(property="message", type="string", example="Exceptions error"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'username' => 'required',
                'password' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => $validator->errors()->all(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $credentials = [
                'username' => $request->username,
                'password' => $request->password,
            ];

            if (! Auth::attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => ['Incorrect username/password.'],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'success' => true,
                'message' => 'User logged successfully',
                'data' => new UserResource(Auth::user()),
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
     *     path="/api/v1/logout",
     *     operationId="logoutUser",
     *     tags={"Auth"},
     *     security={{"sanctum":{}}},
     *     summary="Logout a user",
     *     description="Logs out the authenticated user and deletes the access token",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful logout",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="User logged out successfully")
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
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'User logged out successfully',
            ], Response::HTTP_OK);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error',
                'message' => $e->getMessage(),
            ], Response::HTTP_EXPECTATION_FAILED);
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error',
                    'errors' => $validator->errors()->all(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $user = User::where('email', $request->email)->first();
            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error!',
                    'errors' => ['User not found.'],
                ], Response::HTTP_NOT_FOUND);
            }

            $temporaryPassword = Str::random(8); // For testing i can use hardcode
            $user->password = Hash::make($temporaryPassword);
            $user->save();

            Mail::to($user->email)->queue(new TemporaryPasswordMail($temporaryPassword));

            return response()->json([
                'success' => true,
                'message' => 'A temporary password has been sent to your email.',
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
