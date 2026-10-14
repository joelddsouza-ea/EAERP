<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Workshop\Http\Controllers\CustomerController;
use App\Modules\Workshop\Http\Controllers\VehicleController;
use App\Modules\Workshop\Http\Controllers\JobCardController;
use App\Modules\Workshop\Http\Controllers\BayController;
use App\Modules\Workshop\Http\Controllers\ServicePackageController;
use App\Modules\Workshop\Http\Controllers\MasterDataController;
use App\Modules\Workshop\Http\Controllers\JobSheetController;
use App\Modules\Workshop\Http\Controllers\JobCardIssueController;
use App\Modules\Workshop\Http\Controllers\JobSheetMasterController;

Route::prefix('v1/workshop')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    // Master Data (Read-only lookups)
    Route::get('vehicle-makes', [MasterDataController::class, 'vehicleMakes']);
    Route::get('vehicle-makes/{make}/models', [MasterDataController::class, 'vehicleModels']);
    Route::get('vehicle-categories', [MasterDataController::class, 'vehicleCategories']);
    Route::get('oil-types', [MasterDataController::class, 'oilTypes']);
    Route::get('service-addons', [MasterDataController::class, 'serviceAddons']);

    // Service Packages
    Route::get('service-packages', [ServicePackageController::class, 'index']);
    Route::get('service-packages/{package}', [ServicePackageController::class, 'show']);
    Route::get('service-packages/{package}/pricing', [ServicePackageController::class, 'pricing']);

    // Customers
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/vehicles', [CustomerController::class, 'vehicles']);
    Route::get('customers/{customer}/job-cards', [CustomerController::class, 'jobCards']);
    Route::get('customers/search/phone', [CustomerController::class, 'searchByPhone']);

    // Vehicles
    Route::apiResource('vehicles', VehicleController::class);
    Route::get('vehicles/{vehicle}/service-history', [VehicleController::class, 'serviceHistory']);
    Route::get('vehicles/search/registration', [VehicleController::class, 'searchByRegistration']);

    // Bays
    Route::apiResource('bays', BayController::class);
    Route::get('bays/status/all', [BayController::class, 'allStatus']);

    // Job Cards
    Route::apiResource('job-cards', JobCardController::class);

    // Job Card Status & Assignments
    Route::get('job-cards/{jobCard}/transitions', [JobCardController::class, 'transitions']);
    Route::patch('job-cards/{jobCard}/status', [JobCardController::class, 'updateStatus']);
    Route::post('job-cards/{jobCard}/assign-technician', [JobCardController::class, 'assignTechnician']);
    Route::post('job-cards/{jobCard}/assign-bay', [JobCardController::class, 'assignBay']);

    // Job Card Items & Addons
    Route::post('job-cards/{jobCard}/items', [JobCardController::class, 'addItem']);
    Route::put('job-cards/{jobCard}/items/{item}', [JobCardController::class, 'updateItem']);
    Route::delete('job-cards/{jobCard}/items/{item}', [JobCardController::class, 'removeItem']);
    Route::post('job-cards/{jobCard}/addons', [JobCardController::class, 'addAddon']);
    Route::delete('job-cards/{jobCard}/addons/{addon}', [JobCardController::class, 'removeAddon']);

    // Job Card Checklists
    Route::get('job-cards/{jobCard}/checklists', [JobCardController::class, 'checklists']);
    Route::patch('job-cards/{jobCard}/checklists/{checklist}/items/{item}', [JobCardController::class, 'updateChecklistItem']);

    // Job Card Photos
    Route::get('job-cards/{jobCard}/photos', [JobCardController::class, 'photos']);
    Route::post('job-cards/{jobCard}/photos', [JobCardController::class, 'uploadPhoto']);
    Route::delete('job-cards/{jobCard}/photos/{photo}', [JobCardController::class, 'deletePhoto']);

    // Spare Part Requests
    Route::get('job-cards/{jobCard}/spare-requests', [JobCardController::class, 'spareRequests']);
    Route::post('job-cards/{jobCard}/spare-requests', [JobCardController::class, 'createSpareRequest']);
    Route::patch('job-cards/{jobCard}/spare-requests/{spareRequest}/status', [JobCardController::class, 'updateSpareRequestStatus']);

    // QC
    Route::get('job-cards/{jobCard}/qc', [JobCardController::class, 'qcDetails']);
    Route::post('job-cards/{jobCard}/qc-signoff', [JobCardController::class, 'qcSignoff']);

    // Follow-ups
    Route::get('job-cards/{jobCard}/follow-ups', [JobCardController::class, 'followUps']);
    Route::post('job-cards/{jobCard}/follow-ups', [JobCardController::class, 'createFollowUp']);
    Route::patch('follow-ups/{followUp}', [JobCardController::class, 'updateFollowUp']);

    // Dashboard & Reports
    Route::get('dashboard/stats', [JobCardController::class, 'dashboardStats']);
    Route::get('technicians/{technician}/workload', [JobCardController::class, 'technicianWorkload']);
    Route::get('follow-ups/pending', [JobCardController::class, 'pendingFollowUps']);

    // Digital job sheets (child IDs must belong to the job card)
    Route::prefix('job-cards/{jobCard}')->scopeBindings()->group(function () {
        Route::middleware('permission:workshop.job-sheets.view|workshop.pre-delivery.view')->group(function () {
            Route::get('job-sheets', [JobSheetController::class, 'index']);
            Route::get('job-sheets/{sheet}', [JobSheetController::class, 'show']);
            Route::get('pre-delivery', [JobSheetController::class, 'preDelivery']);
        });

        Route::middleware('permission:workshop.job-sheets.update|workshop.pre-delivery.update')->group(function () {
            Route::patch('job-sheets/{sheet}/items/{item}', [JobSheetController::class, 'updateItem']);
            Route::post('job-sheets/{sheet}/items', [JobSheetController::class, 'addItem']);
            Route::post('job-sheets/{sheet}/items/{item}/media', [JobSheetController::class, 'uploadMedia']);
            Route::delete('job-sheets/{sheet}/items/{item}/media/{media}', [JobSheetController::class, 'deleteMedia']);
            Route::patch('media/{media}', [JobSheetController::class, 'updateMedia']);
        });

        Route::post('job-sheets/{sheet}/submit', [JobSheetController::class, 'submit'])
            ->middleware('permission:workshop.job-sheets.complete|workshop.pre-delivery.update');
        Route::post('job-sheets/{sheet}/reopen', [JobSheetController::class, 'reopen'])
            ->middleware('permission:workshop.job-sheets.reopen');
        Route::post('job-sheets-sync', [JobSheetController::class, 'sync'])
            ->middleware('permission:workshop.job-sheets.manage');

        // Customer issues, additional jobs, observations
        Route::get('issues', [JobCardIssueController::class, 'issues'])->middleware('permission:workshop.job-sheets.view');
        Route::get('additional-jobs', [JobCardIssueController::class, 'additionalJobs'])->middleware('permission:workshop.job-sheets.view');
        Route::get('observations', [JobCardIssueController::class, 'observations'])->middleware('permission:workshop.job-sheets.view');

        Route::middleware('permission:workshop.job-sheets.update')->group(function () {
            Route::post('issues', [JobCardIssueController::class, 'storeIssue']);
            Route::patch('issues/{issue}', [JobCardIssueController::class, 'updateIssue']);
            Route::delete('issues/{issue}', [JobCardIssueController::class, 'destroyIssue']);
            Route::post('additional-jobs', [JobCardIssueController::class, 'storeAdditionalJob']);
            Route::patch('additional-jobs/{additionalJob}', [JobCardIssueController::class, 'updateAdditionalJob']);
            Route::delete('additional-jobs/{additionalJob}', [JobCardIssueController::class, 'destroyAdditionalJob']);
            Route::post('observations', [JobCardIssueController::class, 'storeObservation']);
            Route::patch('observations/{observation}', [JobCardIssueController::class, 'updateObservation']);
            Route::delete('observations/{observation}', [JobCardIssueController::class, 'destroyObservation']);
        });

        // Body-shop insurance claim details
        Route::get('insurance', [JobCardIssueController::class, 'insurance'])->middleware('permission:workshop.job-cards.view');
        Route::put('insurance', [JobCardIssueController::class, 'updateInsurance'])->middleware('permission:workshop.job-cards.update');
    });

    // Job-sheet masters
    Route::prefix('job-sheet-masters')->scopeBindings()->group(function () {
        Route::get('templates', [JobSheetMasterController::class, 'index'])->middleware('permission:workshop.job-sheet-masters.view');
        Route::get('templates/{template}', [JobSheetMasterController::class, 'show'])->middleware('permission:workshop.job-sheet-masters.view');
        Route::post('templates', [JobSheetMasterController::class, 'store'])->middleware('permission:workshop.job-sheet-masters.create');

        Route::middleware('permission:workshop.job-sheet-masters.update')->group(function () {
            Route::put('templates/{template}', [JobSheetMasterController::class, 'update']);
            Route::post('templates/{template}/sections', [JobSheetMasterController::class, 'storeSection']);
            Route::put('templates/{template}/sections/{section}', [JobSheetMasterController::class, 'updateSection']);
            Route::delete('templates/{template}/sections/{section}', [JobSheetMasterController::class, 'destroySection']);
            Route::post('sections/{section}/items', [JobSheetMasterController::class, 'storeItem']);
            Route::put('sections/{section}/items/{item}', [JobSheetMasterController::class, 'updateItem']);
            Route::post('sections/{section}/items/reorder', [JobSheetMasterController::class, 'reorderItems']);
        });
    });
});

// Job-sheet media files: authorised by a short-lived URL signature instead of the bearer token
Route::get('v1/workshop/media/{media}/stream', [JobSheetController::class, 'stream'])
    ->middleware('signed')
    ->name('workshop.media.stream');
