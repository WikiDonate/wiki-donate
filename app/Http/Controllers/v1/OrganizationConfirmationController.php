<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Services\Organization\OrganizationEmailConfirmationService;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationConfirmationController extends Controller
{
    /**
     * Confirm an organization's PayPal receiving email via the token link.
     * Public: the recipient is not a platform user.
     */
    public function confirm(Request $request, OrganizationEmailConfirmationService $service)
    {
        $token = (string) $request->input('token', '');

        if ($token === '') {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation token is required.',
                'errors' => ['Confirmation token is required.'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $organization = $service->confirm($token);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [$this->apiErrorMessage($e)],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'success' => true,
            'message' => 'PayPal email confirmed successfully.',
            'data' => [
                'organization_name' => $organization->name,
                'paypal_email' => $organization->paypal_email,
            ],
        ], Response::HTTP_OK);
    }
}
