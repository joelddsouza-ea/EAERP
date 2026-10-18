<?php

namespace App\Core\Services\WhatsApp;

/**
 * How WhatsApp messages leave the system: the share link (a person sends from the workshop
 * phone) or Interakt (the API sends them). Chosen in Settings › WhatsApp.
 */
interface WhatsAppChannel
{
    /** Channel name stored on the message log */
    public function name(): string;

    /** Whether a person sends every message from the workshop phone */
    public function needsManualSend(): bool;

    /** Link that opens WhatsApp with the message ready to send (also the fallback for the API) */
    public function shareUrl(string $phone, string $body): ?string;
}
