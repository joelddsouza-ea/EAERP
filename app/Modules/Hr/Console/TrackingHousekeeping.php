<?php

namespace App\Modules\Hr\Console;

use App\Core\Support\Modules;
use App\Modules\Hr\Services\LocationService;
use Illuminate\Console\Command;

/**
 * Every few minutes: stops sharing for anyone no longer punched in (a new day, a voided
 * punch). With --prune (nightly) also deletes positions older than the retention days.
 */
class TrackingHousekeeping extends Command
{
    protected $signature = 'hr:tracking {--prune : also delete old positions}';

    protected $description = 'Stop location sharing for staff not punched in; prune old positions';

    public function handle(LocationService $locations): int
    {
        if (!Modules::hr()) {
            $this->info('Attendance & payroll is switched off.');

            return self::SUCCESS;
        }

        $this->info('Stopped: ' . $locations->stopStale());
        if ($this->option('prune')) {
            $this->info('Deleted positions: ' . $locations->prune());
        }

        return self::SUCCESS;
    }
}
