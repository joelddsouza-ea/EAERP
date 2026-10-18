<?php

namespace App\Modules\CustomerApp\Services\Ai;

use App\Core\Support\WorkshopSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings › AI assistant (owner): the Anthropic API key (stored encrypted, never shown in
 * full) and the model.
 */
class AiSettings
{
    public static function apiKey(): ?string
    {
        $stored = WorkshopSettings::get('ai.api_key');
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
        WorkshopSettings::set('ai.api_key', $key === '' ? null : Crypt::encryptString($key));
    }

    public static function maskedKey(): ?string
    {
        $key = self::apiKey();

        return $key === null ? null : str_repeat('•', 8) . substr($key, -4);
    }

    public static function model(): string
    {
        $model = (string) WorkshopSettings::get('ai.model');

        return array_key_exists($model, AiAssistant::MODELS) ? $model : 'claude-opus-5-5';
    }
}
