<?php

namespace App\Exceptions;

use Exception;

/**
 * Intentional, user-safe error messages (validation-style business rule
 * failures from services). Controllers may expose getMessage() for these.
 *
 * Any other exception reaching a controller catch block is unexpected and
 * must be reported + replaced with a generic message (see
 * Controller::apiErrorMessage) so SQL errors and paths never leak.
 */
class UserFacingException extends Exception
{
    //
}
