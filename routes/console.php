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
