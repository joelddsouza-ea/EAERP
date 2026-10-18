<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 6: yesterday's attendance (absent / weekly off / holiday / leave, missing out-punches)
Illuminate\Support\Facades\Schedule::command('hr:close-attendance')->dailyAt('00:30');

// Phase 7 CRM: journey steps, SLA alerts, no-shows, reminders; daily retention enrolments
Illuminate\Support\Facades\Schedule::command('crm:run')->everyFifteenMinutes();
Illuminate\Support\Facades\Schedule::command('crm:daily')->dailyAt('07:00');

// Phase 8: stop location sharing for staff no longer punched in; prune old positions nightly
Illuminate\Support\Facades\Schedule::command('hr:tracking')->everyTenMinutes();
Illuminate\Support\Facades\Schedule::command('hr:tracking --prune')->dailyAt('00:40');

// Phase 8: scheduled WhatsApp messages that came due go through Interakt (when switched on)
Illuminate\Support\Facades\Schedule::command('whatsapp:send-due')->everyFiveMinutes();

// Phase 9: customer app inbox reminders (service due, insurance / PUC / documents, pending work)
Illuminate\Support\Facades\Schedule::command('customerapp:reminders')->dailyAt('09:30');
