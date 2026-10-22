<?php

namespace App\Core\Services\WhatsApp;

use App\Core\Support\WorkshopSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Settings › WhatsApp: share links (default) or Interakt. The API key is stored encrypted
 * and never sent back to the screen in full.
 */
class WhatsAppSettings
{
    public const SHARE = 'share';
    public const INTERAKT = 'interakt';

    public static function provider(): string
    {
        return WorkshopSettings::get('whatsapp.provider') === self::INTERAKT ? self::INTERAKT : self::SHARE;
    }

    /** Interakt is switched on and has a key */
    public static function apiOn(): bool
    {
        return self::provider() === self::INTERAKT && self::apiKey() !== null;
    }

    public static function apiKey(): ?string
    {
        $stored = WorkshopSettings::get('whatsapp.interakt.api_key');
        if (!$stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function setApiKey(?string $key): void
    {
        $key = trim((string) $key);
        WorkshopSettings::set('whatsapp.interakt.api_key', $key === '' ? null : Crypt::encryptString($key));
    }

    public static function maskedKey(): ?string
    {
        $key = self::apiKey();

        return $key === null ? null : str_repeat('•', 8) . substr($key, -4);
    }

    public static function webhookSecret(): string
    {
        $secret = WorkshopSettings::get('whatsapp.interakt.webhook_secret');
        if (!$secret) {
            $secret = Str::random(32);
            WorkshopSettings::set('whatsapp.interakt.webhook_secret', $secret);
        }

        return $secret;
    }

    /**
     * The secret key entered in Interakt's webhook settings: when set, every webhook call must
     * carry a matching Interakt-Signature (HMAC-SHA256 of the body). Stored encrypted.
     */
    public static function signingSecret(): ?string
    {
        $stored = WorkshopSettings::get('whatsapp.interakt.signing_secret');
        if (!$stored) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function setSigningSecret(?string $secret): void
    {
        $secret = trim((string) $secret);
        WorkshopSettings::set('whatsapp.interakt.signing_secret', $secret === '' ? null : Crypt::encryptString($secret));
    }

    /**
     * Interakt-Signature: "sha256=" + hex HMAC-SHA256 of the raw body with the shared secret.
     */
    public static function signatureValid(string $rawBody, ?string $header): bool
    {
        $secret = self::signingSecret();
        if ($secret === null) {
            return true;
        }
        $given = strtolower(trim((string) $header));
        $given = str_starts_with($given, 'sha256=') ? substr($given, 7) : $given;

        return $given !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $given);
    }

    public static function webhookUrl(): string
    {
        return \App\Core\Support\PublicUrl::to('/api/v1/public/whatsapp/interakt/' . self::webhookSecret());
    }

    public static function setProvider(string $provider): void
    {
        $was = self::provider();
        WorkshopSettings::set('whatsapp.provider', $provider === self::INTERAKT ? self::INTERAKT : self::SHARE);

        // Only messages made after switching on go by API: the old queue isn't blasted out
        if ($provider === self::INTERAKT && $was !== self::INTERAKT) {
            WorkshopSettings::set('whatsapp.interakt.enabled_at', now()->toIso8601String());
        }
    }

    public static function enabledAt(): ?string
    {
        return WorkshopSettings::get('whatsapp.interakt.enabled_at');
    }

    public static function baseUrl(): string
    {
        return rtrim((string) WorkshopSettings::get('whatsapp.interakt.base_url'), '/');
    }

    public static function countryCode(): string
    {
        return (string) WorkshopSettings::get('whatsapp.interakt.country_code') ?: '+91';
    }
}
