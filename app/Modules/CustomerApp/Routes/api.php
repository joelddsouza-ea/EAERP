<?php

use App\Modules\CustomerApp\Http\Controllers\CustomerAppController as App;
use App\Modules\CustomerApp\Http\Controllers\CustomerAppStaffController as Staff;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- customer app (customers)

Route::prefix('v1/customer')->middleware('module:customer_app')->group(function () {
    // Sign in: a link the workshop sent, or mobile number + one-time code
    Route::post('auth/link', [App::class, 'signInWithLink'])->middleware('throttle:customer-sign-in');
    Route::post('auth/code', [App::class, 'requestCode'])->middleware('throttle:customer-code');
    Route::post('auth/verify', [App::class, 'verifyCode'])->middleware('throttle:customer-sign-in');

    Route::middleware(['auth.customer', 'throttle:customer'])->group(function () {
        Route::post('auth/logout', [App::class, 'logout']);
        Route::get('home', [App::class, 'home']);

        Route::get('jobs', [App::class, 'jobs']);
        Route::get('jobs/{job}', [App::class, 'job'])->whereNumber('job');
        Route::post('jobs/{job}/payment-claims', [App::class, 'claimPayment'])->whereNumber('job')->middleware('throttle:customer-heavy');
        Route::post('jobs/{job}/explain', [App::class, 'explain'])->whereNumber('job')->middleware('throttle:customer-heavy');

        Route::get('vehicles/{vehicle}', [App::class, 'serviceBook'])->whereNumber('vehicle');
        Route::get('vehicles/{vehicle}/history.pdf', [App::class, 'serviceBookPdf'])->whereNumber('vehicle');
        Route::post('vehicles', [App::class, 'addVehicle'])->middleware('throttle:customer-heavy');
        Route::get('makes', [App::class, 'makes']);
        Route::get('makes/{make}/models', [App::class, 'models'])->whereNumber('make');

        Route::get('booking-options', [App::class, 'bookingOptions']);
        Route::get('slots', [App::class, 'slots']);
        Route::get('bookings', [App::class, 'bookings']);
        Route::post('bookings', [App::class, 'book'])->middleware('throttle:customer-heavy');
        Route::post('bookings/{booking}/cancel', [App::class, 'cancelBooking'])->whereNumber('booking');

        Route::get('notifications', [App::class, 'notifications']);
        Route::post('notifications/read', [App::class, 'readNotifications']);
        Route::post('push-subscriptions', [App::class, 'subscribePush']);

        Route::get('wallet', [App::class, 'wallet']);
        Route::get('documents', [App::class, 'documents']);
        Route::post('documents', [App::class, 'addDocument'])->middleware('throttle:customer-heavy');
        Route::delete('documents/{document}', [App::class, 'deleteDocument'])->whereNumber('document');

        Route::get('assistant', [App::class, 'assistantStatus']);
        Route::post('assistant/diagnose', [App::class, 'diagnose'])->middleware('throttle:customer-heavy');
        Route::post('assistant/accident', [App::class, 'accident'])->middleware('throttle:customer-heavy');
        Route::post('assistant/{aiRequest}/inspection', [App::class, 'accidentLead'])->whereNumber('aiRequest')->middleware('throttle:customer-heavy');
    });
});

// Customer documents and UPI screenshots: short-lived signed links (customer app and staff)
Route::get('v1/customer-files/documents/{document}', [Staff::class, 'documentFile'])->middleware('signed')->name('customer-app.document');
Route::get('v1/customer-files/claims/{claim}', [Staff::class, 'claimScreenshot'])->middleware('signed')->name('customer-app.claim-screenshot');

// ---------------------------------------------------------------- staff side

Route::prefix('v1/customer-app')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    Route::get('customers/{customer}/sign-in-code', [Staff::class, 'signInCode'])->middleware('permission:workshop.customers.view');
    Route::get('customers/{customer}/documents', [Staff::class, 'customerDocuments'])->middleware('permission:workshop.customers.view');

    Route::middleware('permission:billing.payments.create')->group(function () {
        Route::get('upi-claims', [Staff::class, 'claims']);
        Route::get('jobs/{jobCard}/upi-claims', [Staff::class, 'jobClaims']);
        Route::post('upi-claims/{claim}/confirm', [Staff::class, 'confirm']);
        Route::post('upi-claims/{claim}/reject', [Staff::class, 'reject']);
    });

    Route::middleware('role:owner')->group(function () {
        Route::get('ai-settings', [Staff::class, 'aiSettings']);
        Route::put('ai-settings', [Staff::class, 'saveAiSettings']);
    });
});
