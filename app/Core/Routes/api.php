<?php

use Illuminate\Support\Facades\Route;
use App\Core\Http\Controllers\Api\AuthController;
use App\Core\Http\Controllers\Api\NotificationController;

/*
|--------------------------------------------------------------------------
| Core API Routes
|--------------------------------------------------------------------------
|
| These routes handle authentication and core functionality.
|
*/

Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Auth routes
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/logout-all', [AuthController::class, 'logoutAll']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::post('/change-password', [AuthController::class, 'changePassword']);
        });

        // In-app notifications (header bell)
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

        // Apply branch context to all authenticated routes
        Route::middleware('branch.context')->group(function () {
            // One search box for any record, and each person's to-do for today
            Route::get('/search', [\App\Core\Http\Controllers\Api\WorkspaceController::class, 'search'])->middleware('throttle:120,1');
            Route::get('/my-day', [\App\Core\Http\Controllers\Api\WorkspaceController::class, 'myDay']);

            // Settings › Modules (owner): switch optional modules on or off
            Route::get('/settings/modules', [\App\Core\Http\Controllers\Api\ModulesController::class, 'index']);
            Route::put('/settings/modules', [\App\Core\Http\Controllers\Api\ModulesController::class, 'update'])->middleware('role:owner');
        });
    });
});
