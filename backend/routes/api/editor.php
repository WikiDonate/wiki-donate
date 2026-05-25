<?php

use App\Http\Controllers\v1\ArticleController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\DonateController;
use App\Http\Controllers\v1\DonationFormulaController;
use App\Http\Controllers\v1\NotificationController;
use App\Http\Controllers\v1\StripeController;
use App\Http\Controllers\v1\TalkController;
use App\Http\Controllers\v1\UserController;
use Illuminate\Support\Facades\Route;

// Editor & Admin shared routes (same paths as original, now role-gated)
Route::middleware(['auth:sanctum', 'role:Admin|Editor'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('changePassword', [UserController::class, 'changePassword']);

    Route::post('donate', [DonateController::class, 'store']);
    Route::post('donate-now', [DonateController::class, 'donateNow']);
    Route::post('record-payment', [DonateController::class, 'recordPaymentApi']);

    Route::prefix('donation-formulas')->group(function () {
        Route::post('/', [DonationFormulaController::class, 'store']);
        Route::get('{uuid}', [DonationFormulaController::class, 'show']);
        Route::put('{uuid}', [DonationFormulaController::class, 'update']);
        Route::delete('{uuid}', [DonationFormulaController::class, 'destroy']);
    });

    Route::prefix('user')->group(function () {
        Route::post('notifications', [NotificationController::class, 'update']);
        Route::get('notifications', [NotificationController::class, 'show']);
        Route::get('get', [UserController::class, 'getUserDetails']);
        Route::put('update', [UserController::class, 'updateUser']);
    });

    Route::prefix('articles')->group(function () {
        Route::get('my', [ArticleController::class, 'myArticles']);
        Route::post('/', [ArticleController::class, 'save']);
        Route::put('update/{slug}', [ArticleController::class, 'update']);
    });

    Route::prefix('talks')->group(function () {
        Route::post('/', [TalkController::class, 'save']);
        Route::put('update/{slug}', [TalkController::class, 'update']);
    });

    Route::post('stripe/card', [StripeController::class, 'addCard']);
    Route::get('stripe/card', [StripeController::class, 'getCard']);
});
