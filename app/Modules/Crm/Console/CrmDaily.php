<?php

namespace App\Modules\Crm\Console;

use App\Modules\Crm\Services\RetentionService;
use Illuminate\Console\Command;

/**
 * Daily: next-service-due, insurance renewal and win-back journeys; leads cool down.
 */
class CrmDaily extends Command
{
    protected $signature = 'crm:daily';

    protected $description = 'Enrol cars due for service / insurance and quiet customers; age lead scores';

    public function handle(RetentionService $retention): int
    {
        if (!\App\Core\Support\Modules::crm()) {
            $this->info('CRM is switched off.');

            return self::SUCCESS;
        }

        $counts = $retention->daily();
        $this->info("service due: {$counts['service_due']}, insurance: {$counts['insurance']}, win back: {$counts['win_back']}");

        return self::SUCCESS;
    }
}
