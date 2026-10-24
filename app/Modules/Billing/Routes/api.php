<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\JobBillingController;
use App\Modules\Billing\Http\Controllers\PaymentController;
use App\Modules\Billing\Http\Controllers\ReportController;

Route::prefix('v1/billing')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    // Invoices
    Route::apiResource('invoices', InvoiceController::class);
    Route::post('invoices/from-job-card/{jobCard}', [InvoiceController::class, 'createFromJobCard']);
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf']);

    // Invoice Items
    Route::post('invoices/{invoice}/items', [InvoiceController::class, 'addItem']);
    Route::put('invoices/{invoice}/items/{item}', [InvoiceController::class, 'updateItem']);
    Route::delete('invoices/{invoice}/items/{item}', [InvoiceController::class, 'removeItem']);

    // Payments
    Route::apiResource('payments', PaymentController::class)->except(['update', 'destroy']);
    Route::get('invoices/{invoice}/payments', [PaymentController::class, 'invoicePayments']);
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'recordPayment']);
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'downloadReceipt']);

    // Reports and the dashboard's money figures: only for people who may see them
    Route::middleware('permission:billing.reports.view|billing.invoices.view|billing.payments.view')->group(function () {
        Route::get('reports/daily-collection', [ReportController::class, 'dailyCollection']);
        Route::get('reports/outstanding', [ReportController::class, 'outstanding']);
        Route::get('reports/invoice-register', [ReportController::class, 'invoiceRegister']);
        Route::get('reports/payment-summary', [ReportController::class, 'paymentSummary']);
        Route::get('dashboard', [ReportController::class, 'dashboard']);
    });
});

// A job's Billing & Delivery: payments, proforma → tax invoice / close, gate pass, vehicle out.
// job.lifecycle moves the job on (Invoiced, Gate Pass Generated, Delivered) after each change.
Route::prefix('v1/workshop/job-cards/{jobCard}/billing')
    ->middleware(['auth:sanctum', 'branch.context', 'job.lifecycle'])
    ->group(function () {
        Route::middleware('permission:billing.invoices.view|billing.payments.create')->group(function () {
            Route::get('/', [JobBillingController::class, 'show']);
            Route::get('bills/{invoice}/pdf', [JobBillingController::class, 'billPdf']);
            Route::get('payments/{payment}/receipt', [JobBillingController::class, 'receiptPdf']);
            Route::get('gate-pass/pdf', [JobBillingController::class, 'gatePassPdf']);
        });

        Route::post('payments', [JobBillingController::class, 'recordPayment'])->middleware('permission:billing.payments.create');

        Route::middleware('permission:billing.proforma.manage')->group(function () {
            Route::post('proforma', [JobBillingController::class, 'createProforma']);
            Route::post('bills/{invoice}/refresh', [JobBillingController::class, 'refreshProforma']);
            Route::delete('bills/{invoice}', [JobBillingController::class, 'discardProforma']);
            Route::post('bills/{invoice}/items', [JobBillingController::class, 'addItem']);
            Route::patch('bills/{invoice}/items/{item}', [JobBillingController::class, 'updateItem']);
            Route::delete('bills/{invoice}/items/{item}', [JobBillingController::class, 'removeItem']);
        });

        Route::middleware('permission:billing.proforma.approve')->group(function () {
            Route::post('bills/{invoice}/approve', [JobBillingController::class, 'approve']);
        });

        // Tax invoice or close on the proforma, and refunds: back office, managers, owner (not advisors)
        Route::middleware('permission:billing.invoices.finalize')->group(function () {
            Route::post('bills/{invoice}/convert', [JobBillingController::class, 'convert']);
            Route::post('bills/{invoice}/close', [JobBillingController::class, 'close']);
            // Paid beyond the bill: refund or keep as the customer's credit
            Route::post('settle-excess', [JobBillingController::class, 'settleExcess']);
        });

        // The advisor has checked the job: back office is asked to make the bill
        Route::post('request', [JobBillingController::class, 'requestBill'])->middleware('permission:workshop.job-cards.change-status');

        Route::post('gate-pass/override', [JobBillingController::class, 'overrideGatePass'])
            ->middleware('permission:workshop.job-cards.override-guards');
        Route::post('gate-pass/vehicle-out', [JobBillingController::class, 'vehicleOut'])
            ->middleware('permission:workshop.job-cards.change-status|billing.payments.create');
    });
