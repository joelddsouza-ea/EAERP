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

    // The staff app's first-run screen checks it reached an EA Workshop server
    Route::get('/app/ping', [\App\Core\Http\Controllers\Api\AppSettingsController::class, 'ping'])->middleware('throttle:60,1');

    // Interakt delivery updates and customer replies (the secret in the address is the key)
    Route::post('/public/whatsapp/interakt/{secret}', [\App\Core\Http\Controllers\Api\WhatsAppWebhookController::class, 'interakt'])->middleware('throttle:600,1');

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

            // Settings › App address & links, Customer app (owner)
            Route::middleware('role:owner')->group(function () {
                Route::get('/settings/app', [\App\Core\Http\Controllers\Api\AppSettingsController::class, 'show']);
                Route::put('/settings/app', [\App\Core\Http\Controllers\Api\AppSettingsController::class, 'update']);
            });

            // CRM › WhatsApp settings (was Settings › WhatsApp): share links or Interakt, the template for each
            // message, button actions, contact / event sync (screen in the CRM; also used without the CRM module)
            Route::middleware('permission:crm.whatsapp.manage')->prefix('settings/whatsapp')->group(function () {
                Route::get('/', [\App\Core\Http\Controllers\Api\WhatsAppSettingsController::class, 'show']);
                Route::put('/', [\App\Core\Http\Controllers\Api\WhatsAppSettingsController::class, 'update']);
                Route::post('/test', [\App\Core\Http\Controllers\Api\WhatsAppSettingsController::class, 'test'])->middleware('throttle:10,1');
                Route::get('/templates', [\App\Core\Http\Controllers\Api\WhatsAppSettingsController::class, 'templates']);
                Route::put('/templates', [\App\Core\Http\Controllers\Api\WhatsAppSettingsController::class, 'saveTemplates']);
            });
        });
    });
});
