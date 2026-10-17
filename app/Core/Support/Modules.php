<?php

namespace App\Core\Support;

/**
 * Optional modules the owner can switch off (Settings › Modules), like the purchases
 * module. All start on. While one is off its screens and API answer 404, its scheduled
 * jobs do nothing, and the rest of the system works without it:
 *  - accounts: nothing is posted to the books (switching it back on, "Bring the books up
 *    to date" posts what happened meanwhile);
 *  - crm: no leads, bookings, journeys or reminders; job cards are made directly as before;
 *  - hr: no attendance, leave or payroll; "Collected by staff" payments are not offered.
 */
class Modules
{
    public const SWITCHABLE = [
        'accounts' => 'Accounts',
        'crm' => 'CRM & leads',
        'hr' => 'Attendance & payroll',
    ];

    public static function enabled(string $module): bool
    {
        return (bool) WorkshopSettings::get("modules.{$module}");
    }

    public static function accounts(): bool
    {
        return self::enabled('accounts');
    }

    public static function crm(): bool
    {
        return self::enabled('crm');
    }

    public static function hr(): bool
    {
        return self::enabled('hr');
    }
}
