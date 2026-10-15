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
use App\Modules\Workshop\Http\Controllers\EstimateController;
use App\Modules\Workshop\Http\Controllers\CustomerChannelController;
use App\Modules\Workshop\Http\Controllers\ServiceReportController;
use App\Modules\Workshop\Http\Controllers\PublicJobController;
use App\Modules\Workshop\Http\Controllers\PricingMasterController;
use App\Modules\Workshop\Http\Controllers\PartsDeskController;
use App\Modules\Workshop\Http\Controllers\IntakeController;
use App\Modules\Workshop\Http\Controllers\JobCardFormController;
use App\Modules\Workshop\Http\Controllers\JobCardAssignmentController;
use App\Modules\Workshop\Http\Controllers\CarClassController;
use App\Modules\Workshop\Http\Controllers\ObservationRemarkController;
use App\Modules\Workshop\Http\Controllers\WorkflowStageController;
use App\Modules\Workshop\Http\Controllers\PostServiceController;

// job.lifecycle: after a change to a job card, the job moves on to the stage the facts allow
Route::prefix('v1/workshop')->middleware(['auth:sanctum', 'branch.context', 'job.lifecycle'])->group(function () {
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

    // Create / Edit Job Card choices: plans with oil grades + prices for the car, repair types, job sheets
    Route::get('job-card-options', [JobCardFormController::class, 'options'])
        ->middleware('permission:workshop.job-cards.view');

    // Job Card Status & Assignments
    Route::get('job-cards/{jobCard}/transitions', [JobCardController::class, 'transitions']);
    Route::patch('job-cards/{jobCard}/status', [JobCardController::class, 'updateStatus']);

    // After the work: trial drive and washing, recorded by the advisor (the job then moves on by itself)
    Route::middleware('permission:workshop.qc.update|workshop.job-cards.change-status')->group(function () {
        Route::post('job-cards/{jobCard}/post-service/trial', [PostServiceController::class, 'trial']);
        Route::post('job-cards/{jobCard}/post-service/wash', [PostServiceController::class, 'wash']);
    });
    Route::post('job-cards/{jobCard}/assign-technician', [JobCardController::class, 'assignTechnician']);
    Route::post('job-cards/{jobCard}/assign-bay', [JobCardController::class, 'assignBay']);

    // Several technicians (one lead) and bay moves, with the candidates to choose from
    Route::get('job-cards/{jobCard}/assignments', [JobCardAssignmentController::class, 'show'])
        ->middleware('permission:workshop.job-cards.view');
    Route::middleware('permission:workshop.job-cards.update')->group(function () {
        Route::post('job-cards/{jobCard}/technicians', [JobCardAssignmentController::class, 'addTechnician']);
        Route::delete('job-cards/{jobCard}/technicians/{user}', [JobCardAssignmentController::class, 'removeTechnician']);
        Route::post('job-cards/{jobCard}/bay', [JobCardAssignmentController::class, 'moveBay']);
    });

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
            Route::post('issues/{issue}/media', [JobCardIssueController::class, 'uploadIssueMedia']);
            Route::delete('issues/{issue}/media/{media}', [JobCardIssueController::class, 'deleteIssueMedia']);
            Route::post('additional-jobs', [JobCardIssueController::class, 'storeAdditionalJob']);
            Route::patch('additional-jobs/{additionalJob}', [JobCardIssueController::class, 'updateAdditionalJob']);
            Route::delete('additional-jobs/{additionalJob}', [JobCardIssueController::class, 'destroyAdditionalJob']);
            Route::post('observations', [JobCardIssueController::class, 'storeObservation']);
            Route::post('observations/submit', [JobCardIssueController::class, 'submitObservations']);
            Route::patch('observations/{observation}', [JobCardIssueController::class, 'updateObservation']);
            Route::delete('observations/{observation}', [JobCardIssueController::class, 'destroyObservation']);
        });

        // Estimates (versioned) and customer approval
        Route::middleware('permission:workshop.estimates.view')->group(function () {
            Route::get('estimates', [EstimateController::class, 'index']);
            Route::get('estimates/{estimate}', [EstimateController::class, 'show']);
            Route::get('estimates/{estimate}/pdf', [EstimateController::class, 'pdf']);
            Route::get('estimate-decisions', [EstimateController::class, 'decisionLog']);
            Route::get('media-library', [EstimateController::class, 'media']);
        });

        Route::middleware('permission:workshop.estimates.manage')->group(function () {
            Route::post('estimates', [EstimateController::class, 'draft']);
            Route::put('estimates/{estimate}', [EstimateController::class, 'update']);
            Route::post('estimates/{estimate}/refresh-plan', [EstimateController::class, 'refreshPlan']);
            Route::post('estimates/{estimate}/lines', [EstimateController::class, 'storeLine']);
            Route::put('estimates/{estimate}/lines/{line}', [EstimateController::class, 'updateLine']);
            Route::delete('estimates/{estimate}/lines/{line}', [EstimateController::class, 'destroyLine']);
            Route::post('estimates/{estimate}/comments', [EstimateController::class, 'comment']);
        });

        Route::post('estimates/{estimate}/send', [EstimateController::class, 'send'])
            ->middleware('permission:workshop.estimates.send');
        Route::post('estimate-decisions', [EstimateController::class, 'decide'])
            ->middleware('permission:workshop.estimates.decide-on-behalf');

        // Parts pricing: advisor asks the spare parts coordinator, then adds priced parts with a margin
        Route::get('part-requests', [PartsDeskController::class, 'forJobCard'])
            ->middleware('permission:workshop.estimates.view|workshop.spare-parts.view');
        // Advisor: ask prices directly, forward the technician's parts, cancel
        Route::middleware(['permission:workshop.spare-parts.create', 'permission:workshop.estimates.manage'])->group(function () {
            Route::post('part-requests', [PartsDeskController::class, 'requestPrices']);
            Route::post('part-requests/forward', [PartsDeskController::class, 'forward']);
            Route::post('part-requests/{sparePartRequest}/cancel', [PartsDeskController::class, 'cancel']);
        });
        // Technician: list parts per additional job, then "Submit to SA for Parts"
        Route::middleware('permission:workshop.job-sheets.update')->group(function () {
            Route::post('additional-jobs/{additionalJob}/parts', [PartsDeskController::class, 'addTechnicianPart']);
            Route::post('issues/{issue}/parts', [PartsDeskController::class, 'addIssuePart']);
            Route::delete('part-requests/{sparePartRequest}', [PartsDeskController::class, 'removeTechnicianPart']);
            Route::post('parts-submission', [PartsDeskController::class, 'submitToAdvisor']);
        });
        Route::middleware('permission:workshop.estimates.manage')->group(function () {
            Route::post('estimates/{estimate}/parts', [PartsDeskController::class, 'addToEstimate']);
            Route::get('labour-charges', [PartsDeskController::class, 'labourCharges']);
        });

        // Walk-in intake: trial drive and inventory photos/videos
        Route::get('intake', [IntakeController::class, 'show'])->middleware('permission:workshop.job-cards.view');
        Route::middleware('permission:workshop.job-cards.update')->group(function () {
            Route::put('intake/trial', [IntakeController::class, 'updateTrial']);
            Route::post('intake/media', [IntakeController::class, 'uploadMedia']);
            Route::delete('intake/media/{media}', [IntakeController::class, 'deleteMedia']);
        });

        // Customer link and WhatsApp messages
        Route::middleware('permission:workshop.customer-links.manage')->group(function () {
            Route::get('customer-link', [CustomerChannelController::class, 'link']);
            Route::post('customer-link', [CustomerChannelController::class, 'regenerateLink']);
            Route::delete('customer-link', [CustomerChannelController::class, 'revokeLink']);
            Route::get('customer-messages', [CustomerChannelController::class, 'messages']);
            Route::post('customer-messages/{customerMessage}/sent', [CustomerChannelController::class, 'markSent']);
            Route::post('customer-messages/{customerMessage}/dismiss', [CustomerChannelController::class, 'dismiss']);
            Route::post('customer-messages/approval-reminder', [CustomerChannelController::class, 'remind']);
            Route::post('customer-messages/share-media', [CustomerChannelController::class, 'shareMedia']);
        });

        // Service report (sent to the customer for 360 / ELITE)
        Route::get('report', [ServiceReportController::class, 'show'])->middleware('permission:workshop.reports.view');
        Route::get('report/pdf', [ServiceReportController::class, 'pdf'])->middleware('permission:workshop.reports.view');
        Route::put('report', [ServiceReportController::class, 'update'])->middleware('permission:workshop.reports.release');
        Route::post('report/release', [ServiceReportController::class, 'release'])->middleware('permission:workshop.reports.release');

        // Body-shop insurance claim details
        Route::get('insurance', [JobCardIssueController::class, 'insurance'])->middleware('permission:workshop.job-cards.view');
        Route::put('insurance', [JobCardIssueController::class, 'updateInsurance'])->middleware('permission:workshop.job-cards.update');
    });

    // Estimate line suggestions (history + add-ons)
    Route::get('estimate-suggestions', [EstimateController::class, 'suggestions'])
        ->middleware('permission:workshop.estimates.manage');

    // Price masters (plans × car types × oil grades, plan items, oil litres / rates)
    Route::prefix('pricing-masters')->group(function () {
        Route::get('/', [PricingMasterController::class, 'index'])->middleware('permission:workshop.pricing-masters.view');
        Route::get('car-classes', [CarClassController::class, 'index'])->middleware('permission:workshop.pricing-masters.view');

        Route::middleware('permission:workshop.pricing-masters.update')->group(function () {
            Route::put('prices', [PricingMasterController::class, 'updatePrice']);
            Route::put('litres', [PricingMasterController::class, 'updateLitres']);
            Route::put('oil-types/{oilType}', [PricingMasterController::class, 'updateOilType']);
            Route::post('packages', [PricingMasterController::class, 'storePackage']);
            Route::put('packages/{package}', [PricingMasterController::class, 'updatePackage']);
            Route::post('line-items', [PricingMasterController::class, 'storeLineItem']);
            Route::put('line-items/{lineItem}', [PricingMasterController::class, 'updateLineItem']);
            Route::delete('line-items/{lineItem}', [PricingMasterController::class, 'destroyLineItem']);
            Route::post('labour-services', [PricingMasterController::class, 'storeLabourService']);
            Route::put('labour-services/{labourService}', [PricingMasterController::class, 'updateLabourService']);
            Route::put('parts-margin', [PricingMasterController::class, 'updatePartsMargin']);

            // Car classes & models
            Route::post('car-classes', [CarClassController::class, 'storeClass']);
            Route::put('car-classes/{vehicleCategory}', [CarClassController::class, 'updateClass']);
            Route::post('car-makes', [CarClassController::class, 'storeMake']);
            Route::post('car-models', [CarClassController::class, 'storeModel']);
            Route::put('car-models/{vehicleModel}', [CarClassController::class, 'updateModel']);
        });
    });

    // Parts Desk (spare parts coordinator): price requests, supplier quotes, procurement
    Route::prefix('parts-desk')->scopeBindings()->group(function () {
        Route::get('/', [PartsDeskController::class, 'desk'])->middleware('permission:workshop.spare-parts.view');

        Route::middleware('permission:workshop.spare-parts.update')->group(function () {
            Route::post('requests/{sparePartRequest}/quotes', [PartsDeskController::class, 'storeQuote']);
            Route::delete('requests/{sparePartRequest}/quotes/{quote}', [PartsDeskController::class, 'destroyQuote']);
            Route::post('submit', [PartsDeskController::class, 'submit']);
            Route::post('requests/{sparePartRequest}/ordered', [PartsDeskController::class, 'markOrdered']);
            Route::post('requests/{sparePartRequest}/received', [PartsDeskController::class, 'markReceived']);
        });
    });

    Route::get('suppliers', [PartsDeskController::class, 'suppliers'])->middleware('permission:workshop.suppliers.view');
    Route::middleware('permission:workshop.suppliers.manage')->group(function () {
        Route::post('suppliers', [PartsDeskController::class, 'storeSupplier']);
        Route::put('suppliers/{supplier}', [PartsDeskController::class, 'updateSupplier']);
    });

    // Remarks for observations (Customer not approved, Fix at next service, ...)
    Route::get('observation-remarks', [JobCardIssueController::class, 'remarks'])->middleware('permission:workshop.job-sheets.view');
    Route::prefix('job-sheet-masters')->group(function () {
        Route::get('observation-remarks', [ObservationRemarkController::class, 'index'])->middleware('permission:workshop.job-sheet-masters.view');
        Route::post('observation-remarks', [ObservationRemarkController::class, 'store'])->middleware('permission:workshop.job-sheet-masters.update');
        Route::put('observation-remarks/{observationRemark}', [ObservationRemarkController::class, 'update'])->middleware('permission:workshop.job-sheet-masters.update');

        // Job stages: automatic moves and the customer's WhatsApp message per stage
        Route::get('workflow-stages', [WorkflowStageController::class, 'index'])->middleware('permission:workshop.job-sheet-masters.view');
        Route::put('workflow-stages/{workflowStage}', [WorkflowStageController::class, 'update'])->middleware('permission:workshop.job-sheet-masters.update');
    });

    // Intake requirements (minimum inventory photos / videos)
    Route::get('intake-requirements', [IntakeController::class, 'requirements'])->middleware('permission:workshop.job-sheet-masters.view');
    Route::put('intake-requirements', [IntakeController::class, 'updateRequirements'])->middleware('permission:workshop.job-sheet-masters.update');

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

// Customer's login-free job page: the token is the credential (hashed lookup, revocable, expires)
// The page polls 'version' to refresh itself, so it has its own, larger allowance
Route::get('v1/public/j/{token}/version', [PublicJobController::class, 'version'])->middleware('throttle:120,1');
Route::prefix('v1/public/j/{token}')->middleware('throttle:30,1')->group(function () {
    Route::get('/', [PublicJobController::class, 'show']);
    Route::post('decisions', [PublicJobController::class, 'decide']);
    Route::post('comments', [PublicJobController::class, 'comment']);
    Route::get('estimate.pdf', [PublicJobController::class, 'estimatePdf']);
    Route::get('report.pdf', [PublicJobController::class, 'reportPdf']);
    Route::get('bill.pdf', [PublicJobController::class, 'billPdf']);
});
