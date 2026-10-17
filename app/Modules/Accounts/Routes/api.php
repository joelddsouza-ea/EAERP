<?php

use App\Modules\Accounts\Http\Controllers\AccountsController;
use App\Modules\Accounts\Http\Controllers\AccountsReportController;
use App\Modules\Accounts\Http\Controllers\ExpenseController;
use App\Modules\Accounts\Http\Controllers\JournalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/accounts')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    Route::get('dashboard', [AccountsController::class, 'dashboard'])->middleware('permission:accounts.reports.view');

    // Masters › Accounts
    Route::get('masters', [AccountsController::class, 'masters'])->middleware('permission:accounts.view|accounts.settings.manage');
    Route::middleware('permission:accounts.settings.manage')->group(function () {
        Route::post('accounts', [AccountsController::class, 'saveAccount']);
        Route::put('accounts/{account}', [AccountsController::class, 'saveAccount']);
        Route::post('banks', [AccountsController::class, 'saveBank']);
        Route::put('banks/{bank}', [AccountsController::class, 'saveBank']);
        Route::put('settings', [AccountsController::class, 'saveSettings']);
        Route::post('resync', [AccountsController::class, 'resync']);
    });
    Route::post('periods/lock', [AccountsController::class, 'lockPeriod'])->middleware('permission:accounts.period.lock');

    // Expenses
    Route::get('expense-options', [ExpenseController::class, 'options'])->middleware('permission:accounts.expenses.create|accounts.view');
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:accounts.view|accounts.expenses.create');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:accounts.expenses.create');
    Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->middleware('permission:accounts.journals.create');
    Route::get('expenses/{expense}/bill-url', [ExpenseController::class, 'billUrl'])->middleware('permission:accounts.view|accounts.expenses.create');

    // Vouchers
    Route::get('entries/{entry}', [JournalController::class, 'show'])->middleware('permission:accounts.view');
    Route::get('parties', [JournalController::class, 'parties'])->middleware('permission:accounts.view');
    Route::middleware('permission:accounts.journals.create')->group(function () {
        Route::post('entries', [JournalController::class, 'store']);
        Route::post('entries/{entry}/reverse', [JournalController::class, 'reverse']);
    });

    // Books & statements
    Route::middleware('permission:accounts.reports.view')->prefix('reports')->group(function () {
        Route::get('trial-balance', [AccountsReportController::class, 'trialBalance']);
        Route::get('profit-loss', [AccountsReportController::class, 'profitAndLoss']);
        Route::get('balance-sheet', [AccountsReportController::class, 'balanceSheet']);
        Route::get('ledger', [AccountsReportController::class, 'ledger']);
        Route::get('cash-book', [AccountsReportController::class, 'cashBook']);
        Route::get('day-book', [AccountsReportController::class, 'dayBook']);
        Route::get('parties', [AccountsReportController::class, 'parties']);
        Route::get('gst', [AccountsReportController::class, 'gst']);
        Route::get('vouchers.csv', [AccountsReportController::class, 'exportVouchers']);
    });
});

// Expense bill photos / PDFs: authorised by a short-lived URL signature
Route::get('v1/accounts/expense-bills/{expense}', [ExpenseController::class, 'bill'])
    ->middleware('signed')
    ->name('accounts.expense-bill');
