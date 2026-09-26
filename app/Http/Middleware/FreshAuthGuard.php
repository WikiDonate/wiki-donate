<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure role/guard checks see the user for the CURRENT request.
 *
 * Laravel's Sanctum guard (a RequestGuard singleton) memoizes the first user
 * it resolves. In normal PHP-FPM every request boots a fresh container so the
 * memo never leaks, but in long-lived processes (php artisan test reusing one
 * application instance across requests, Octane, queue workers) the memoized
 * user from a previous request can leak into the next one — making a role
 * check pass/fail for the wrong identity.
 *
 * When the incoming request is authenticated through a Bearer token, the
 * resolved guards are flushed right before role/permission middleware runs so
 * the token is re-resolved against the current request. ActingAs-style setups
 * (guards populated programmatically, no token in the request) are left
 * untouched.
 */
class FreshAuthGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null) {
            Auth::forgetGuards();
        }

        return $next($request);
    }
}
