<?php

namespace App\Modules\CustomerApp\Console;

use App\Core\Support\Modules;
use App\Modules\CustomerApp\Services\CustomerExtrasService;
use Illuminate\Console\Command;

/**
 * Daily: reminders in the customer app inbox (service due, insurance / PUC / document expiry,
 * work recommended earlier), each once.
 */
class CustomerAppReminders extends Command
{
    protected $signature = 'customerapp:reminders';

    protected $description = 'Write the customer app reminders for today';

    public function handle(CustomerExtrasService $extras): int
    {
        if (!Modules::customerApp()) {
            $this->info('The customer app is switched off.');

            return self::SUCCESS;
        }

        foreach ($extras->dailyReminders() as $kind => $n) {
            $this->line("{$kind}: {$n}");
        }

        return self::SUCCESS;
    }
}
