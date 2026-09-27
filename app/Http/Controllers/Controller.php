<?php

namespace App\Http\Controllers;

use App\Exceptions\UserFacingException;
use Exception;
use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Wiki Donate API',
    version: '1.0'
)]
abstract class Controller
{
    /**
     * Client-safe error text for catch blocks.
     *
     * Intentional business-rule failures (UserFacingException) keep their
     * message. Everything else is reported and replaced with a generic
     * message so SQL errors and file paths never reach the client.
     */
    protected function apiErrorMessage(Exception $e): string
    {
        if ($e instanceof UserFacingException) {
            return $e->getMessage();
        }

        report($e);

        return 'An unexpected error occurred. Please try again later.';
    }
}
