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

        return $this->messageResult($this->call('post', '/v1/public/message/', $payload));
    }

    /** Free-form kinds Interakt sends inside the 24-hour window */
    public const SESSION_KINDS = ['text' => 'Text', 'image' => 'Image', 'document' => 'Document', 'video' => 'Video', 'audio' => 'Audio'];

    /**
     * A free-form reply (text, or a file with a caption). WhatsApp only allows these within 24 hours
     * of the customer's last message; outside it Interakt refuses and a template must be used.
     *
     * @return array{ok: bool, id: ?string, error: ?string, window_closed?: bool}
     */
    public function sendSession(string $phone, string $kind, ?string $text, ?string $mediaUrl = null, ?string $fileName = null, ?string $callbackData = null): array
    {
        $type = self::SESSION_KINDS[$kind] ?? null;
        if (!$type) {
            return ['ok' => false, 'id' => null, 'error' => 'That kind of message cannot be sent.'];
        }
        $data = array_filter([
            'message' => $text !== null && $text !== '' ? $text : null,
            'mediaUrl' => $kind === 'text' ? null : $mediaUrl,
            'fileName' => in_array($kind, ['document', 'video', 'audio'], true) ? $fileName : null,
        ], fn ($v) => $v !== null);

        $result = $this->messageResult($this->call('post', '/v1/public/message/', array_filter([
            'countryCode' => WhatsAppSettings::countryCode(),
            'phoneNumber' => substr($phone, -10),
            'callbackData' => $callbackData,
            'type' => $type,
            'data' => $data,
        ], fn ($v) => $v !== null)));

        if (!$result['ok'] && str_contains(strtolower((string) $result['error']), '24 hours')) {
            $result['window_closed'] = true;
        }

        return $result;
    }

    /**
     * Add or update a contact in Interakt (Track Users API). Traits replace earlier values;
     * tags can only be added.
     *
     * @return array{ok: bool, error: ?string, retry_after?: int}
     */
    public function trackUser(string $phone, array $traits, array $tags = [], ?string $userId = null): array
    {
        return $this->trackResult($this->call('post', '/v1/public/track/users/', array_filter([
            'userId' => $userId,
            'phoneNumber' => substr($phone, -10),
            'countryCode' => WhatsAppSettings::countryCode(),
            'traits' => $traits ?: null,
            'tags' => $tags ?: null,
        ], fn ($v) => $v !== null)));
    }

    /**
     * Record something that happened to a contact (Track Events API): Interakt's ongoing
     * campaigns can be triggered by it.
     *
     * @return array{ok: bool, error: ?string, retry_after?: int}
     */
    public function trackEvent(string $phone, string $event, array $traits = [], ?string $userId = null): array
    {
        return $this->trackResult($this->call('post', '/v1/public/track/events/', array_filter([
            'userId' => $userId,
            'phoneNumber' => substr($phone, -10),
            'countryCode' => WhatsAppSettings::countryCode(),
            'event' => $event,
            'traits' => $traits ?: null,
        ], fn ($v) => $v !== null)));
    }

    /**
     * The account's templates. Interakt's public docs don't promise this endpoint: when it
     * doesn't answer, the caller says so and names are typed in by hand.
     *
     * @return array{ok: bool, templates: array<int, array>, error: ?string}
     */
    public function listTemplates(): array
    {
        $all = [];
        for ($offset = 0, $page = 0; $page < 20; $page++) {
            $res = $this->call('get', '/v1/public/track/organization/templates', ['offset' => $offset, 'approval_status' => 'ALL']);
            if (!$res['ok']) {
                return ['ok' => false, 'templates' => $all, 'error' => $res['error']];
            }
            $json = $res['json'];
            $rows = $json['results']['templates'] ?? $json['templates'] ?? $json['data'] ?? $json['results'] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                return ['ok' => false, 'templates' => $all, 'error' => 'Interakt answered, but not with a template list.'];
            }
            $all = [...$all, ...$rows];
            $next = $json['results']['next'] ?? $json['next'] ?? null;
            if (!$next || !$rows) {
                break;
            }
            $offset += count($rows);
        }

        return ['ok' => true, 'templates' => $all, 'error' => null];
    }

    /**
     * Give the chat to a staff member in Interakt's own inbox (their Interakt login e-mail).
     *
     * @return array{ok: bool, error: ?string}
     */
    public function assignChat(string $phone, string $agentEmail): array
    {
        $res = $this->call('post', '/v1/public/assignment/', ['user_phone_number' => $phone, 'agent_email' => $agentEmail]);

        return ['ok' => $res['ok'] && ($res['json']['result'] ?? true) !== false, 'error' => $res['error'] ?? ($res['json']['message'] ?? null)];
    }

    /**
     * One request to Interakt. Never throws: the error comes back as text, and a 429 says
     * how long to wait.
     *
     * @return array{ok: bool, status: int, json: array, error: ?string, retry_after: ?int}
     */
    protected function call(string $method, string $path, array $payload = []): array
    {
        $key = WhatsAppSettings::apiKey();
        if ($key === null) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'The Interakt API key is not set.', 'retry_after' => null];
        }

        try {
            // The key from Interakt's Developer Settings is already base64: it goes as is
            $request = Http::withHeaders(['Authorization' => 'Basic ' . $key])
                ->acceptJson()
                ->timeout(10)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
            $response = $method === 'get'
                ? $request->get(WhatsAppSettings::baseUrl() . $path, $payload)
                : $request->post(WhatsAppSettings::baseUrl() . $path, $payload);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Could not reach Interakt: ' . $e->getMessage(), 'retry_after' => 60];
        }

        $json = $response->json() ?? [];
        $json = is_array($json) ? $json : [];
        if ($response->successful()) {
            return ['ok' => true, 'status' => $response->status(), 'json' => $json, 'error' => null, 'retry_after' => null];
        }

        $reason = $json['message'] ?? $response->reason();

        return [
            'ok' => false,
            'status' => $response->status(),
            'json' => $json,
            'error' => 'Interakt: ' . mb_strimwidth((string) $reason, 0, 240, '…') . " (HTTP {$response->status()})",
            'retry_after' => $response->status() === 429 ? max(30, (int) $response->header('Retry-After')) : null,
        ];
    }

    protected function messageResult(array $res): array
    {
        if ($res['ok'] && ($res['json']['result'] ?? false)) {
            return ['ok' => true, 'id' => isset($res['json']['id']) ? (string) $res['json']['id'] : null, 'error' => null];
        }

        return ['ok' => false, 'id' => null, 'error' => $res['error'] ?? ('Interakt: ' . ($res['json']['message'] ?? 'not sent'))];
    }

    protected function trackResult(array $res): array
    {
        $refused = $res['ok'] && ($res['json']['result'] ?? true) === false;
        $out = [
            'ok' => $res['ok'] && !$refused,
            'error' => $refused ? 'Interakt: ' . ($res['json']['message'] ?? 'refused') : $res['error'],
        ];
        if ($res['retry_after']) {
            $out['retry_after'] = $res['retry_after'];
        }

        return $out;
    }
}
