<?php

use App\Http\Controllers\v1\Admin\ArticleController;
use App\Http\Controllers\v1\Admin\DashboardController;
use App\Http\Controllers\v1\Admin\PageContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'role:Admin'])
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('articles', [ArticleController::class, 'index']);
        Route::get('articles/{slug}', [ArticleController::class, 'show']);
        Route::delete('articles/{slug}', [ArticleController::class, 'destroy']);

        Route::get('page-contents/{page}', [PageContentController::class, 'show']);
        Route::put('page-contents/{page}', [PageContentController::class, 'update']);
    });
