<?php

namespace App\Modules\Crm\Console;

use App\Modules\Crm\Services\WhatsApp\InteraktSyncService;
use Illuminate\Console\Command;

/**
 * Send contacts and events waiting for Interakt now (crm:run does it every 15 minutes);
 * --all first queues every lead and customer once (the first sync after switching on).
 */
class InteraktSync extends Command
{
    protected $signature = 'crm:interakt-sync {--all : Queue every open / recent lead and active customer first} {--until-empty : Keep going (a batch a minute) until nothing waits}';

    protected $description = 'Send contacts and events to Interakt';

    public function handle(InteraktSyncService $sync): int
    {
        if (!InteraktSyncService::contactsOn() && !InteraktSyncService::eventsOn()) {
            $this->warn('Interakt or the contact / event sync is switched off (CRM › WhatsApp).');

            return self::SUCCESS;
        }
        if ($this->option('all')) {
            $this->info('Queued: ' . $sync->queueAll());
        }

        do {
            $r = $sync->drain();
            $this->line("sent {$r['sent']}, failed {$r['failed']}, waiting {$r['waiting']}");
            $more = $this->option('until-empty') && $r['waiting'] > 0 && ($r['sent'] > 0 || $r['failed'] > 0);
            if ($more) {
                // Interakt allows 300 calls a minute on Growth
                sleep(60);
            }
        } while ($more);

        return self::SUCCESS;
    }
}
