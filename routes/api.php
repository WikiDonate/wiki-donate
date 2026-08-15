<?php

use App\Http\Controllers\v1\ArticleController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\ContactController;
use App\Http\Controllers\v1\DonationFormulaController;
use App\Http\Controllers\v1\PageController;
use App\Http\Controllers\v1\PayPalController;
use App\Http\Controllers\v1\PayPalWebhookController;
use App\Http\Controllers\v1\StripeController;
use App\Http\Controllers\v1\StripeWebhookController;
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
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:3,10');

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

    // Stripe Checkout (public)
    Route::post('stripe/checkout', [StripeController::class, 'createCheckoutSession'])
        ->middleware('auth:sanctum');
    Route::get('stripe/checkout/{sessionId}', [StripeController::class, 'getCheckoutSession']);

    // Stripe Webhook (public, no auth)
    Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

    // PayPal Checkout (create + capture are callable without an authenticated
    // user so guest donations and the post-redirect success-page flow work)
    Route::post('paypal/create-order', [PayPalController::class, 'createOrder'])
        ->middleware(['throttle:10,1', OptionalAuth::class]);
    Route::post('paypal/capture-order', [PayPalController::class, 'captureOrder'])
        ->middleware(['throttle:20,1', OptionalAuth::class]);

    // PayPal Webhook (public, no auth)
    Route::post('webhooks/paypal', [PayPalWebhookController::class, 'handleWebhook']);

    // Public page content
    Route::get('page-contents/{page}', [PageController::class, 'show']);

    // Role-specific route files (loaded inside v1 prefix)
    require __DIR__.'/api/admin.php';
});
