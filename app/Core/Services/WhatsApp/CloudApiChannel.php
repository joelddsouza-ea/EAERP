<?php

namespace App\Core\Services\WhatsApp;

/**
 * WhatsApp Business (Cloud) API. Not connected yet: needs a verified business
 * account and approved message templates. Messages stay in the log as failed so
 * nothing is lost, and the share link still works as a fallback.
 */
class CloudApiChannel implements WhatsAppChannel
{
    public function name(): string
    {
        return 'whatsapp_api';
    }

    public function deliver(string $phone, string $body): ?string
    {
        return 'WhatsApp Business API is not configured yet.';
    }

    public function needsManualSend(): bool
    {
        return false;
    }

    public function shareUrl(string $phone, string $body): ?string
    {
        return (new ShareLinkChannel())->shareUrl($phone, $body);
    }
}
