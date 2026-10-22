<?php

use App\Modules\Crm\Http\Controllers\BookingController;
use App\Modules\Crm\Http\Controllers\CrmMasterController;
use App\Modules\Crm\Http\Controllers\InsightsController;
use App\Modules\Crm\Http\Controllers\LeadController;
use App\Modules\Crm\Http\Controllers\MessageQueueController;
use App\Modules\Crm\Http\Controllers\PublicCrmController;
use App\Modules\Crm\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/crm')->middleware(['auth:sanctum', 'branch.context', 'module:crm'])->group(function () {
    // Leads
    Route::middleware('permission:crm.leads.view|crm.leads.work|crm.bookings.manage')->group(function () {
        Route::get('leads', [LeadController::class, 'index']);
        Route::get('lead-options', [LeadController::class, 'options']);
        Route::get('leads/{lead}', [LeadController::class, 'show']);
        Route::get('leads/{leadId}/messages', [MessageQueueController::class, 'forLead']);
    });
    Route::middleware('permission:crm.leads.work|crm.leads.manage|crm.bookings.manage')->group(function () {
        Route::post('leads', [LeadController::class, 'store']);
        Route::put('leads/{lead}', [LeadController::class, 'update']);
        Route::post('leads/{lead}/call', [LeadController::class, 'call']);
        Route::post('leads/{lead}/note', [LeadController::class, 'note']);
        Route::post('leads/{lead}/follow-up', [LeadController::class, 'followUp']);
        Route::post('leads/{lead}/interested', [LeadController::class, 'interested']);
        Route::post('leads/{lead}/lost', [LeadController::class, 'lost']);
        Route::post('leads/{lead}/reopen', [LeadController::class, 'reopen']);
        Route::post('leads/{lead}/opt-out', [LeadController::class, 'optOut']);
        Route::post('leads/{lead}/quote', [LeadController::class, 'quote']);
    });
    Route::middleware('permission:crm.leads.manage')->group(function () {
        Route::post('leads/{lead}/assign', [LeadController::class, 'assign']);
        Route::post('leads-import', [LeadController::class, 'import']);
    });

    // Bookings
    Route::middleware('permission:crm.bookings.manage')->group(function () {
        Route::get('bookings', [BookingController::class, 'index']);
        Route::get('booking-availability', [BookingController::class, 'availability']);
        Route::post('bookings', [BookingController::class, 'store']);
        Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm']);
        Route::post('bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow']);
        Route::post('bookings/{booking}/arrive', [BookingController::class, 'arrive'])->middleware('permission:workshop.job-cards.create');
    });

    // Messages to send (share mode)
    Route::middleware('permission:crm.messages.send')->group(function () {
        Route::get('messages', [MessageQueueController::class, 'index']);
        Route::post('messages/{message}/sent', [MessageQueueController::class, 'sent']);
        Route::post('messages/{message}/dismiss', [MessageQueueController::class, 'dismiss']);
    });

    // Insights, feedback, customer 360
    Route::get('insights', [InsightsController::class, 'summary'])->middleware('permission:crm.reports.view');
    Route::get('tiles', [InsightsController::class, 'tiles'])->middleware('permission:crm.reports.view|crm.leads.manage');
    Route::get('feedback', [InsightsController::class, 'feedback'])->middleware('permission:crm.reports.view|crm.leads.manage');
    Route::post('feedback/{feedback}/resolve', [InsightsController::class, 'resolveFeedback'])->middleware('permission:crm.reports.view|crm.leads.manage');
    Route::get('customers/{customer}/timeline', [InsightsController::class, 'customer'])->middleware('permission:workshop.customers.view');

    // Settings › CRM
    Route::get('masters', [CrmMasterController::class, 'index'])->middleware('permission:crm.settings.manage|crm.leads.manage');
    Route::middleware('permission:crm.settings.manage')->prefix('masters')->group(function () {
        Route::post('sources', [CrmMasterController::class, 'saveSource']);
        Route::put('sources/{source}', [CrmMasterController::class, 'saveSource']);
        Route::post('sources/{source}/new-key', [CrmMasterController::class, 'newWebhookKey']);
        Route::post('campaigns', [CrmMasterController::class, 'saveCampaign']);
        Route::put('campaigns/{campaign}', [CrmMasterController::class, 'saveCampaign']);
        Route::post('lost-reasons', [CrmMasterController::class, 'saveLostReason']);
        Route::put('lost-reasons/{reason}', [CrmMasterController::class, 'saveLostReason']);
        Route::post('creatives', [CrmMasterController::class, 'saveCreative']);
        Route::post('creatives/{creative}', [CrmMasterController::class, 'saveCreative']);
        Route::post('journeys', [CrmMasterController::class, 'saveJourney']);
        Route::put('journeys/{journey}', [CrmMasterController::class, 'saveJourney']);
        Route::put('slots', [CrmMasterController::class, 'saveSlots']);
        Route::put('settings', [CrmMasterController::class, 'saveSettings']);
    });
    Route::post('masters/campaigns/{campaign}/spend', [CrmMasterController::class, 'saveSpend'])->middleware('permission:crm.campaigns.spend');

    // WhatsApp inbox (Interakt): chats with leads and customers
    Route::middleware('permission:crm.whatsapp.inbox')->prefix('whatsapp')->group(function () {
        Route::get('conversations', [WhatsAppController::class, 'index']);
        Route::get('unread', [WhatsAppController::class, 'unread']);
        Route::get('people', [WhatsAppController::class, 'people']);
        Route::get('conversations/{conversation}', [WhatsAppController::class, 'show']);
        Route::post('conversations/{conversation}/reply', [WhatsAppController::class, 'reply'])->middleware('throttle:60,1');
        Route::post('conversations/{conversation}/template', [WhatsAppController::class, 'template'])->middleware('throttle:30,1');
        Route::post('conversations/{conversation}/assign', [WhatsAppController::class, 'assign']);
        Route::post('conversations/{conversation}/status', [WhatsAppController::class, 'status']);
        Route::post('conversations/{conversation}/lead', [WhatsAppController::class, 'createLead']);
        Route::get('leads/{lead}', [WhatsAppController::class, 'forLead']);
        Route::post('leads/{lead}', [WhatsAppController::class, 'startForLead']);
    });
    // Interakt tools (CRM › WhatsApp settings)
    Route::middleware('permission:crm.whatsapp.manage')->prefix('whatsapp')->group(function () {
        Route::post('templates/fetch', [WhatsAppController::class, 'fetchTemplates'])->middleware('throttle:10,1');
        Route::get('templates/interakt', [WhatsAppController::class, 'cachedTemplates']);
        Route::get('sync', [WhatsAppController::class, 'syncStatus']);
        Route::post('sync/all', [WhatsAppController::class, 'syncAll'])->middleware('throttle:5,1');
    });
});

// Attachments we send in WhatsApp chats: Interakt fetches them by this signed address
Route::get('v1/public/wa-media/{name}', [WhatsAppController::class, 'media'])->middleware('throttle:120,1')->where('name', '[0-9a-f\-]{36}\.[a-z0-9]{1,5}');

Route::get('v1/crm/creatives/{creative}/file', [CrmMasterController::class, 'creativeFile'])
    ->middleware(['signed', 'module:crm'])
    ->name('crm.creative.file');

// No login: leads from ad platforms / connectors / the website, and customers' CRM links
Route::match(['get', 'post'], 'v1/public/leads/{key}', [PublicCrmController::class, 'webhook'])->middleware(['throttle:120,1', 'module:crm']);
Route::prefix('v1/public/c/{token}')->middleware(['throttle:60,1', 'module:crm'])->group(function () {
    Route::get('/', [PublicCrmController::class, 'show']);
    Route::get('slots', [PublicCrmController::class, 'slots']);
    Route::post('book', [PublicCrmController::class, 'book'])->middleware('throttle:10,1');
    Route::post('feedback', [PublicCrmController::class, 'feedback'])->middleware('throttle:10,1');
    Route::post('review', [PublicCrmController::class, 'reviewClicked']);
    Route::post('refer', [PublicCrmController::class, 'refer'])->middleware('throttle:10,1');
    Route::post('stop', [PublicCrmController::class, 'stop']);
    Route::get('media', [PublicCrmController::class, 'media']);
});
