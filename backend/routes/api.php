<?php

use App\Http\Controllers\v1\ArticleController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\CauseController;
use App\Http\Controllers\v1\ContactController;
use App\Http\Controllers\v1\DonationFormulaController;
use App\Http\Controllers\v1\PageController;
use App\Http\Controllers\v1\PayPalCharityController;
use App\Http\Controllers\v1\TalkController;
use App\Http\Controllers\v1\UserController;
use App\Http\Middleware\OptionalAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('user', [UserController::class, 'register'])->middleware(
        'throttle:5,10',
    );
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgotPassword', [AuthController::class, 'forgotPassword']);

    // Email verification routes
    Route::get('/email/verify/{id}/{hash}', [UserController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/resend', [UserController::class, 'resendVerificationEmail'])
        ->middleware(['auth:sanctum', 'throttle:6,1'])
        ->name('verification.resend');
    Route::get('search', [ArticleController::class, 'search']);
    Route::post('contact', [ContactController::class, 'store']);

    // Donation Formula routes (public)
    Route::get('donation-formulas/article/{slug}', [
        DonationFormulaController::class,
        'index',
    ]);

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

    // Talk routes (public)
    Route::prefix('talks')->group(function () {
        Route::get('{slug}', [TalkController::class, 'show']);
        Route::get('{slug}/history', [TalkController::class, 'history']);
    });

    // Cause routes (public)
    Route::prefix('causes')->group(function () {
        Route::get('/', [CauseController::class, 'getCauses']);
        Route::get('search', [CauseController::class, 'searchCause']);
        Route::get('{id}', [CauseController::class, 'getCauseDetails']);
    });

    // PayPal Charity Scraper
    Route::get('paypal/charities', [PayPalCharityController::class, 'index']);

    // Public page content
    Route::get('page-contents/{page}', [PageController::class, 'show']);

    // Role-specific route files (loaded inside v1 prefix)
    require __DIR__.'/api/editor.php';
    require __DIR__.'/api/admin.php';
});
