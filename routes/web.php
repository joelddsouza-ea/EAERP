<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application.
|
*/

Route::get('/', function () {
    return response()->json([
        'name' => 'EAERP - Car Workshop ERP',
        'version' => '1.0.0',
        'api_docs' => '/api/health',
    ]);
});
