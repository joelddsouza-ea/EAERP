<?php

namespace App\Core\Console;

use App\Core\Services\WhatsApp\WhatsAppDispatcher;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Console\Command;

/**
 * Every five minutes while Interakt is on: sends scheduled messages that have come due
 * (journey steps, reminders). Messages due at once go when they are made.
 */
class SendDueWhatsApp extends Command
{
    protected $signature = 'whatsapp:send-due';

    protected $description = 'Send due WhatsApp messages through Interakt (when switched on)';

    public function handle(WhatsAppDispatcher $dispatcher): int
    {
        if (!WhatsAppSettings::apiOn()) {
            $this->info('Interakt is switched off: messages wait for the share link.');

            return self::SUCCESS;
        }

        $this->info('Sent: ' . $dispatcher->sendDue());

        return self::SUCCESS;
    }
}
