<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
| These routes are loaded by the RouteServiceProvider and all of them
| will be assigned to the "api" middleware group.
|
| Module routes are loaded automatically from their respective module
| service providers:
| - Core routes: app/Core/Routes/api.php
| - UserAdmin routes: app/Modules/UserAdmin/Routes/api.php
| - Workshop routes: app/Modules/Workshop/Routes/api.php
| - Billing routes: app/Modules/Billing/Routes/api.php
|
*/

// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});
