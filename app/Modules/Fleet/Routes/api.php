<?php

use App\Modules\Fleet\Http\Controllers\FleetAppController as App;
use App\Modules\Fleet\Http\Controllers\FleetStaffController as Staff;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- customer app (fleet owner and drivers)

Route::prefix('v1/customer')->middleware(['module:customer_app', 'module:fleet', 'auth.customer', 'throttle:customer'])->group(function () {
    // Owner only
    Route::get('fleet', [App::class, 'dashboard']);
    Route::get('fleet/drivers', [App::class, 'drivers']);
    Route::post('fleet/drivers', [App::class, 'addDriver'])->middleware('throttle:customer-heavy');
    Route::put('fleet/drivers/{driver}', [App::class, 'updateDriver'])->whereNumber('driver');
    Route::post('fleet/drivers/{driver}/sign-out', [App::class, 'signOutDriver'])->whereNumber('driver');

    // Owner and drivers (a driver: their cabs only)
    Route::get('reports', [App::class, 'reports']);
    Route::post('reports', [App::class, 'report'])->middleware('throttle:customer-heavy');
    Route::get('vehicles/{vehicle}/odometer', [App::class, 'odometer'])->whereNumber('vehicle');
    Route::post('vehicles/{vehicle}/odometer', [App::class, 'logOdometer'])->whereNumber('vehicle')->middleware('throttle:customer-heavy');
});

// Report photos / videos and odometer photos: short-lived signed links (app and staff)
Route::get('v1/fleet-files/reports/{media}', [Staff::class, 'reportMedia'])->middleware('signed')->name('fleet.report-media');
Route::get('v1/fleet-files/odometer/{reading}', [Staff::class, 'odometerPhoto'])->middleware('signed')->name('fleet.odometer-photo');

// ---------------------------------------------------------------- staff side

Route::prefix('v1/fleet')->middleware(['auth:sanctum', 'branch.context', 'module:fleet'])->group(function () {
    Route::middleware('permission:workshop.customers.view')->group(function () {
        Route::get('customers/{customer}', [Staff::class, 'show']);
        Route::get('vehicles/{vehicle}/odometer', [Staff::class, 'odometer']);
    });
    // The job card form: the drivers of a fleet cab
    Route::get('customers/{customer}/drivers', [Staff::class, 'driversForJob'])->middleware('permission:workshop.job-cards.create');

    Route::middleware('permission:workshop.customers.update')->group(function () {
        Route::put('customers/{customer}', [Staff::class, 'update']);
        Route::post('customers/{customer}/drivers', [Staff::class, 'addDriver']);
        Route::put('customers/{customer}/drivers/{driver}', [Staff::class, 'updateDriver']);
        Route::post('customers/{customer}/drivers/{driver}/sign-out', [Staff::class, 'signOutDriver']);
        Route::post('vehicles/{vehicle}/odometer', [Staff::class, 'logOdometer']);
    });

    Route::middleware('permission:workshop.fleet.reports')->group(function () {
        Route::get('reports', [Staff::class, 'reports']);
        Route::post('reports/{report}/book', [Staff::class, 'book']);
        Route::post('reports/{report}/close', [Staff::class, 'close']);
    });
});
