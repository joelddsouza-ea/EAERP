<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application.
| The SPA catch-all route serves the Vue.js frontend for all non-API routes.
|
*/

// SPA catch-all route - serves the Vue.js frontend
// Customer app (its own Vue app, installable as "Express Autocare")
Route::get('/app/{any?}', fn () => view('customer'))->where('any', '.*');

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
