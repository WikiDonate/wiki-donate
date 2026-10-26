<?php

use App\Http\Controllers\v1\ArticleController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\CharitySearchController;
use App\Http\Controllers\v1\ContactController;
use App\Http\Controllers\v1\DonationFormulaController;
use App\Http\Controllers\v1\OrganizationConfirmationController;
use App\Http\Controllers\v1\PageController;
use App\Http\Controllers\v1\PaymentController;
use App\Http\Controllers\v1\PaymentMethodController;
use App\Http\Controllers\v1\PaymentWebhookController;
use App\Http\Controllers\v1\UserController;
use App\Http\Middleware\OptionalAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('user', [UserController::class, 'register'])->middleware(
        'throttle:5,10',
    );
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('forgotPassword', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,10');

    // Email verification routes
    Route::get('/email/verify/{id}/{hash}', [UserController::class, 'verifyEmail'])
        ->middleware(['throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/resend-by-email', [UserController::class, 'resendVerificationByEmail'])
        ->middleware(['throttle:6,1'])
        ->name('verification.resend-by-email');
    Route::get('search', [ArticleController::class, 'search']);
    // Verified US-charity autocomplete (valid EIN only, cached server-side)
    Route::get('charities/search', [CharitySearchController::class, 'search'])
        ->middleware('throttle:30,1');
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:3,10');

    // Organization PayPal-email confirmation (public: recipient is not a user)
    Route::post('organizations/confirm-email', [OrganizationConfirmationController::class, 'confirm'])
        ->middleware('throttle:10,1');

    // Donation Formula routes (public)
    Route::get('donation-formulas/article/{slug}', [
        DonationFormulaController::class,
        'index',
    ]);

    // Editor & Admin shared routes — loaded BEFORE the public `articles/{slug}`
    // route so static segments (e.g. `articles/my`) are not shadowed by the
    // parameterised slug route.
    require __DIR__.'/api/editor.php';

    // Articles routes (public / optional auth)
    Route::prefix('articles')->group(function () {
        Route::middleware(OptionalAuth::class)->group(function () {
            Route::get('/', [ArticleController::class, 'index']);
        });

        Route::middleware(OptionalAuth::class)->group(function () {
            Route::get('{slug}', [ArticleController::class, 'show']);
            Route::get('{slug}/history', [ArticleController::class, 'history']);
        });
    });

    // Generic donor checkout: /payment/{method}/create-order + /capture-order,
    // where {method} is a payment_methods.code. Resolution enforces `active`
    // and fails loudly for unknown/unbound methods, so enabling a new method
    // needs no controller, route, or front-end change.
    Route::post('payment/{method}/create-order', [PaymentController::class, 'createOrder'])
        ->where('method', '[a-z0-9_-]+')
        ->middleware(['throttle:10,1', OptionalAuth::class]);
    Route::post('payment/{method}/capture-order', [PaymentController::class, 'captureOrder'])
        ->where('method', '[a-z0-9_-]+')
        ->middleware(['throttle:20,1', OptionalAuth::class]);

    // Read-only status poll for hosted-checkout return pages (Stripe).
    Route::get('payment/{method}/status/{reference}', [PaymentController::class, 'status'])
        ->where('method', '[a-z0-9_-]+')
        ->middleware('throttle:60,1');

    // Method-parameterised webhooks: /webhooks/{method} (e.g. /webhooks/paypal,
    // /webhooks/stripe). Resolution ignores `active` so in-flight events for a
    // just-disabled method are still verified and processed.
    Route::post('webhooks/{method}', [PaymentWebhookController::class, 'handle'])
        ->where('method', '[a-z0-9_-]+')
        ->middleware('throttle:60,1');

    // Active payment-method registry (public): drives donor checkout pickers
    // and the admin filters/badges/payout rail. Only active=1 rows are exposed.
    Route::get('payment-methods', [PaymentMethodController::class, 'index'])
        ->middleware('throttle:60,1');

    // Browser-safe credential for a registered method (PayPal client ID /
    // Stripe publishable key). Ignores `active` so billing can still load
    // Stripe.js while Stripe is disabled for donations.
    Route::get('payment-methods/{code}/public-key', [PaymentMethodController::class, 'publicKey'])
        ->where('code', '[a-z0-9_-]+')
        ->middleware('throttle:60,1');

    // Public page content
    Route::get('page-contents/{page}', [PageController::class, 'show']);

    // Role-specific route files (loaded inside v1 prefix)
    require __DIR__.'/api/admin.php';
});
