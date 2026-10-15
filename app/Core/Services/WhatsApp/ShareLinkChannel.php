<?php

namespace App\Core\Services\WhatsApp;

/**
 * No API account: the advisor taps a wa.me link that opens WhatsApp on the
 * workshop phone with the message filled in, and sends it themselves.
 */
class ShareLinkChannel implements WhatsAppChannel
{
    public function name(): string
    {
        return 'whatsapp_share';
    }

    public function deliver(string $phone, string $body): ?string
    {
        return null;
    }

    public function needsManualSend(): bool
    {
        return true;
    }

    public function shareUrl(string $phone, string $body): ?string
    {
        return 'https://wa.me/' . $phone . '?text=' . rawurlencode($body);
    }

    /**
     * Indian mobile number in WhatsApp's international form (91XXXXXXXXXX), or
     * null if it does not look like one. Accepts 10 digits, 0-prefixed and +91.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }

        return preg_match('/^91[6-9]\d{9}$/', $digits) ? $digits : null;
    }
}
