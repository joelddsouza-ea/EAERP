<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Billing\Http\Controllers\InvoiceController;
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

    // Reports
    Route::get('reports/daily-collection', [ReportController::class, 'dailyCollection']);
    Route::get('reports/outstanding', [ReportController::class, 'outstanding']);
    Route::get('reports/invoice-register', [ReportController::class, 'invoiceRegister']);
    Route::get('reports/payment-summary', [ReportController::class, 'paymentSummary']);

    // Dashboard
    Route::get('dashboard', [ReportController::class, 'dashboard']);
});
