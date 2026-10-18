<?php

namespace App\Core\Support;

/**
 * The address customers and outside services reach the ERP at (Settings › App address & links).
 * Links sent out (job pages, CRM links, webhooks, media for WhatsApp) use it; while it is
 * not set they use this server's own address as before.
 */
class PublicUrl
{
    public static function base(): ?string
    {
        $url = trim((string) WorkshopSettings::get('app.public_url'));

        return $url === '' ? null : rtrim($url, '/');
    }

    public static function to(string $path = ''): string
    {
        $base = self::base();
        if ($base === null) {
            return url($path);
        }

        return $base . '/' . ltrim($path, '/');
    }
}
