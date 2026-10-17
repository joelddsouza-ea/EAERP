<?php

namespace App\Modules\Hr\Console;

use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Console\Command;

/**
 * Nightly: fills yesterday's attendance for every employee (absent, weekly off, holiday,
 * leave) and flags missing out-punches.
 */
class CloseAttendance extends Command
{
    protected $signature = 'hr:close-attendance {date? : YYYY-MM-DD, default yesterday}';

    protected $description = "Fill the day's attendance for every employee";

    public function handle(AttendanceService $attendance): int
    {
        if (!\App\Core\Support\Modules::hr()) {
            $this->info('Attendance & payroll is switched off.');

            return self::SUCCESS;
        }

        $date = $this->argument('date') ?: today()->subDay()->toDateString();
        $count = $attendance->closeDay($date);
        $this->info("{$date}: {$count} employees");

        return self::SUCCESS;
    }
}
