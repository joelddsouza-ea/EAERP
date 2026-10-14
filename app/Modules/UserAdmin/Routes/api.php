<?php

use Illuminate\Support\Facades\Route;
use App\Modules\UserAdmin\Http\Controllers\UserController;
use App\Modules\UserAdmin\Http\Controllers\RoleController;
use App\Modules\UserAdmin\Http\Controllers\BranchController;

Route::prefix('v1/user-admin')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    // Users
    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/activate', [UserController::class, 'activate']);
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::get('users/{user}/activity', [UserController::class, 'activity']);

    // Roles
    Route::apiResource('roles', RoleController::class);
    Route::get('roles/{role}/permissions', [RoleController::class, 'permissions']);
    Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

    // Branches
    Route::apiResource('branches', BranchController::class);
    Route::post('branches/{branch}/activate', [BranchController::class, 'activate']);
    Route::post('branches/{branch}/deactivate', [BranchController::class, 'deactivate']);
    Route::get('branches/{branch}/users', [BranchController::class, 'users']);

    // Permissions list (read-only)
    Route::get('permissions', [RoleController::class, 'allPermissions']);
});
