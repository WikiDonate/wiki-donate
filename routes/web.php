<?php

use Illuminate\Support\Facades\Route;

// SPA entry — serves the Vue app for all non-API, non-asset GET requests.
// The negative-lookahead keeps API, static assets and health checks intact.
Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|build|storage|vendor|sanctum|up|when|oauth|_ignition|telescope|horizon).*$')
    ->name('spa');
