<?php

use Illuminate\Support\Facades\Route;
use App\Modules\UserAdmin\Http\Controllers\UserController;
use App\Modules\UserAdmin\Http\Controllers\RoleController;
use App\Modules\UserAdmin\Http\Controllers\BranchController;

// Every route checks the user-admin permissions: without them any signed-in user
// could create users, change roles or reset passwords.
Route::prefix('v1/user-admin')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    // Users
    Route::get('users', [UserController::class, 'index'])->middleware('permission:user-admin.users.view');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:user-admin.users.view');
    Route::get('users/{user}/activity', [UserController::class, 'activity'])->middleware('permission:user-admin.users.view');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:user-admin.users.create');
    Route::middleware('permission:user-admin.users.update')->group(function () {
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/activate', [UserController::class, 'activate']);
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
    });
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:user-admin.users.delete');

    // Roles (the list is also needed to pick a user's role)
    Route::get('roles', [RoleController::class, 'index'])
        ->middleware('permission:user-admin.roles.view|user-admin.users.create|user-admin.users.update');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:user-admin.roles.view');
    Route::get('roles/{role}/permissions', [RoleController::class, 'permissions'])->middleware('permission:user-admin.roles.view');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:user-admin.roles.create');
    Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])->middleware('permission:user-admin.roles.update');
    Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:user-admin.roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:user-admin.roles.delete');

    // Branches (the list is also needed to place a user in branches)
    Route::get('branches', [BranchController::class, 'index'])
        ->middleware('permission:user-admin.branches.view|user-admin.users.create|user-admin.users.update');
    Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:user-admin.branches.view');
    Route::get('branches/{branch}/users', [BranchController::class, 'users'])->middleware('permission:user-admin.branches.view');
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:user-admin.branches.create');
    Route::middleware('permission:user-admin.branches.update')->group(function () {
        Route::match(['put', 'patch'], 'branches/{branch}', [BranchController::class, 'update']);
        Route::post('branches/{branch}/activate', [BranchController::class, 'activate']);
        Route::post('branches/{branch}/deactivate', [BranchController::class, 'deactivate']);
    });
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('permission:user-admin.branches.delete');

    // Permissions list (read-only)
    Route::get('permissions', [RoleController::class, 'allPermissions'])->middleware('permission:user-admin.roles.view');
});
