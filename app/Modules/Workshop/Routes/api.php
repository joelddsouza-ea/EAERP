<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Workshop\Http\Controllers\CustomerController;
use App\Modules\Workshop\Http\Controllers\VehicleController;
use App\Modules\Workshop\Http\Controllers\JobCardController;
use App\Modules\Workshop\Http\Controllers\BayController;
use App\Modules\Workshop\Http\Controllers\ServicePackageController;
use App\Modules\Workshop\Http\Controllers\MasterDataController;

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
    Route::patch('job-cards/{jobCard}/spare-requests/{request}/status', [JobCardController::class, 'updateSpareRequestStatus']);

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
});
