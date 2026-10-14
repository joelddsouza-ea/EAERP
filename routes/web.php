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
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
