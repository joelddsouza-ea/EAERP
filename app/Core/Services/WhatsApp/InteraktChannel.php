<?php

namespace App\Core\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business API through Interakt (https://api.interakt.ai). Business-initiated
 * messages must use a template approved in Interakt; WhatsAppDispatcher picks the template
 * and its values. Anything that can't go by API falls back to the share link.
 */
class InteraktChannel implements WhatsAppChannel
{
    public function name(): string
    {
        return 'interakt';
    }

    public function needsManualSend(): bool
    {
        return false;
    }

    public function shareUrl(string $phone, string $body): ?string
    {
        return (new ShareLinkChannel())->shareUrl($phone, $body);
    }

    /**
     * Send an approved template.
     *
     * @param string $phone 91XXXXXXXXXX
     * @param array<int, string> $bodyValues
     * @param array<int, string> $headerValues media URL for an image / video / document header
     * @param array<string, array<int, string>> $buttonValues
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function sendTemplate(string $phone, string $template, string $language, array $bodyValues = [], array $headerValues = [], array $buttonValues = [], ?string $callbackData = null): array
    {
        $key = WhatsAppSettings::apiKey();
        if ($key === null) {
            return ['ok' => false, 'id' => null, 'error' => 'The Interakt API key is not set.'];
        }

        $template = array_filter([
            'name' => $template,
            'languageCode' => $language ?: 'en',
            'headerValues' => $headerValues ?: null,
            'bodyValues' => $bodyValues ?: null,
            'buttonValues' => $buttonValues ?: null,
        ], fn ($v) => $v !== null);

        $payload = array_filter([
            'countryCode' => WhatsAppSettings::countryCode(),
            'phoneNumber' => substr($phone, -10),
            'callbackData' => $callbackData,
            'type' => 'Template',
            'template' => $template,
        ], fn ($v) => $v !== null);

        try {
            // The key from Interakt's Developer Settings is already base64: it goes as is
            $response = Http::withHeaders(['Authorization' => 'Basic ' . $key])
                ->acceptJson()
                ->timeout(10)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post(WhatsAppSettings::baseUrl() . '/v1/public/message/', $payload);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'id' => null, 'error' => 'Could not reach Interakt: ' . $e->getMessage()];
        }

        $json = $response->json() ?? [];
        if ($response->successful() && ($json['result'] ?? false)) {
            return ['ok' => true, 'id' => isset($json['id']) ? (string) $json['id'] : null, 'error' => null];
        }

        $reason = $json['message'] ?? $response->reason();

        return ['ok' => false, 'id' => null, 'error' => 'Interakt: ' . mb_strimwidth((string) $reason, 0, 240, '…') . " (HTTP {$response->status()})"];
    }
}
