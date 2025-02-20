<?php

use App\Http\Controllers\v1\ArticleController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\ContactController;
use App\Http\Controllers\v1\DonateController;
use App\Http\Controllers\v1\NotificationController;
use App\Http\Controllers\v1\TalkController;
use App\Http\Controllers\v1\UserController;
use App\Http\Middleware\OptionalAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('user', [UserController::class, 'register'])->middleware('throttle:5,10');
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('changePassword', [UserController::class, 'changePassword'])->middleware('auth:sanctum');
    Route::post('forgotPassword', [AuthController::class, 'forgotPassword']);
    Route::get('search', [ArticleController::class, 'search']);
    Route::post('contact', [ContactController::class, 'store']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('donate', [DonateController::class, 'store']);
    });

    // User authenticated routes
    Route::prefix('user')->middleware('auth:sanctum')->group(function () {
        Route::post('notifications', [NotificationController::class, 'update']);
        Route::get('notifications', [NotificationController::class, 'show']);
    });

    // Articles routes
    Route::prefix('articles')->group(function () {
        Route::middleware(OptionalAuth::class)->group(function () {
            Route::get('/', [ArticleController::class, 'index']);
            Route::get('{slug}', [ArticleController::class, 'show']);
            Route::get('{slug}/history', [ArticleController::class, 'history']);
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/', [ArticleController::class, 'save']);
            Route::put('update/{slug}', [ArticleController::class, 'update']);
        });
    });

    // Talk routes
    Route::prefix('talks')->group(function () {
        Route::get('{slug}', [TalkController::class, 'show']);
        Route::get('{slug}/history', [TalkController::class, 'history']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/', [TalkController::class, 'save']);
            Route::put('update/{slug}', [TalkController::class, 'update']);
        });
    });
});
