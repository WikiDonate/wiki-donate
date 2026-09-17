<?php

use App\Http\Controllers\v1\Admin\ArticleController;
use App\Http\Controllers\v1\Admin\DashboardController;
use App\Http\Controllers\v1\Admin\OrganizationPayoutController;
use App\Http\Controllers\v1\Admin\PageContentController;
use App\Http\Controllers\v1\Admin\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'role:Admin'])
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('donations', [DashboardController::class, 'donations']);

        // Organization payouts (append-only ledger, live computed balances)
        Route::get('payouts/allocations', [OrganizationPayoutController::class, 'allocations']);
        Route::get('payouts/history', [OrganizationPayoutController::class, 'history']);
        Route::post('payouts', [OrganizationPayoutController::class, 'store']);

        Route::get('articles', [ArticleController::class, 'index']);
        Route::get('articles/{slug}', [ArticleController::class, 'show']);
        Route::delete('articles/{slug}', [ArticleController::class, 'destroy']);

        // Organization payouts (append-only ledger, live computed balance)
        Route::get('payouts', [PayoutController::class, 'index']);
        Route::get('payouts/allocations', [PayoutController::class, 'allocations']);
        Route::post('payouts', [PayoutController::class, 'store']);

        Route::get('page-contents/{page}', [PageContentController::class, 'show']);
        Route::put('page-contents/{page}', [PageContentController::class, 'update']);

        Route::get('transactions', [TransactionController::class, 'index']);
        Route::get('transactions/summary', [TransactionController::class, 'summary']);
        Route::get('transactions/export', [TransactionController::class, 'export']);
    });
