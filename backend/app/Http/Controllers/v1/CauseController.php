<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Models\Cause;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CauseController extends Controller
{
    /**
     * Get all causes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCauses()
    {
        try {
            $causes = Cause::all();

            return response()->json([
                'success' => true,
                'message' => 'Causes retrieved successfully',
                'data' => $causes,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve causes',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Search causes by title
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchCause(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|string|min:2',
            ]);

            $causes = Cause::where('title', 'like', '%'.$request->title.'%')->get();

            return response()->json([
                'success' => true,
                'message' => 'Causes search results',
                'data' => $causes,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Specific catch for validation errors
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Exception $e) {
            // General catch for all other errors
            return response()->json([
                'success' => false,
                'message' => 'Search failed',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get cause details with associated NGOs
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCauseDetails($id)
    {
        try {
            $cause = Cause::with(['ngos' => function ($query) {
                $query->select('ngos.id', 'ngos.title', 'paypal_account', 'address')
                    ->withPivot('percentage');
            }])->findOrFail($id);

            $formattedNgos = $cause->ngos->map(function ($ngo) {
                return [
                    'id' => $ngo->id,
                    'title' => $ngo->title,
                    'paypal_account' => $ngo->paypal_account,
                    'address' => $ngo->address,
                    'percentage' => $ngo->pivot->percentage,
                ];
            });

            $responseData = [
                'id' => $cause->id,
                'title' => $cause->title,
                'description' => $cause->description,
                'ngos' => $formattedNgos,
                'created_at' => $cause->created_at,
                'updated_at' => $cause->updated_at,
            ];

            return response()->json([
                'success' => true,
                'message' => 'Cause details retrieved successfully',
                'data' => $responseData,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // Specific catch when cause is not found
            return response()->json([
                'success' => false,
                'message' => 'Cause not found',
                'errors' => ['The requested cause does not exist'],
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            // General catch for all other errors
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve cause details',
                'errors' => [$e->getMessage()],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
