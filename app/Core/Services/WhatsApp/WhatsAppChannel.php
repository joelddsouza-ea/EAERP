<?php

namespace App\Core\Services\WhatsApp;

/**
 * How WhatsApp messages leave the system. Selected by config('services.whatsapp.driver').
 */
interface WhatsAppChannel
{
    /** Channel name stored on the message log */
    public function name(): string;

    /**
     * Try to deliver the message. Returns null when it was sent, or the reason it
     * was not (a share channel always leaves it for a person to send).
     */
    public function deliver(string $phone, string $body): ?string;

    /** Whether a person sends the message from the workshop phone */
    public function needsManualSend(): bool;

    /** Link that opens WhatsApp with the message ready to send, if the channel has one */
    public function shareUrl(string $phone, string $body): ?string;
}
