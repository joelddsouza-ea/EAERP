<?php

namespace App\Modules\Crm\Services\WhatsApp;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Templates as Interakt has them: "Fetch from Interakt" lists the account's templates
 * (name, language, category, approval, variables, header, buttons) to pick from, and marks
 * our mapped ones with their approval. Approvals / rejections later arrive by webhook.
 * Interakt's public docs don't promise a list endpoint: when it doesn't answer, names are
 * typed in by hand as before.
 */
class WhatsAppTemplateSync
{
    public const CACHE_KEY = 'whatsapp.interakt.templates_cache';

    public function __construct(protected InteraktChannel $interakt) {}

    /**
     * @return array{ok: bool, error: ?string, templates: array<int, array>, updated: int}
     */
    public function fetch(): array
    {
        $res = $this->interakt->listTemplates();
        if (!$res['ok']) {
            return ['ok' => false, 'error' => ($res['error'] ?? 'Not available') . ' — type the template names in by hand.', 'templates' => $this->cached(), 'updated' => 0];
        }

        $list = collect($res['templates'])->map(fn ($t) => self::normalise((array) $t))->filter(fn ($t) => $t['name'] !== '')->values();
        WorkshopSettings::set(self::CACHE_KEY, $list->take(500)->all());

        $updated = 0;
        foreach (WhatsAppTemplate::whereNotNull('provider_name')->where('provider_name', '!=', '')->get() as $row) {
            $match = $list->first(fn ($t) => $t['name'] === $row->provider_name && ($t['language'] === '' || $t['language'] === $row->language))
                ?? $list->firstWhere('name', $row->provider_name);
            if (!$match) {
                $row->update(['approval_status' => 'NOT_FOUND', 'synced_at' => now()]);
                continue;
            }
            $row->update([
                'approval_status' => $match['status'] ?: null,
                'category' => $match['category'] ?: null,
                'buttons' => $match['buttons'] ?: null,
                'synced_at' => now(),
            ]);
            $updated++;
        }

        return ['ok' => true, 'error' => null, 'templates' => $list->all(), 'updated' => $updated];
    }

    public function cached(): array
    {
        $rows = WorkshopSettings::get(self::CACHE_KEY);

        return is_array($rows) ? $rows : [];
    }

    /**
     * One template from Interakt in our shape (Interakt's field names vary between versions).
     */
    public static function normalise(array $t): array
    {
        $components = $t['components'] ?? null;
        if (is_string($components)) {
            $components = json_decode($components, true);
        }
        $components = is_array($components) ? $components : [];
        $body = (string) ($t['body'] ?? collect($components)->first(fn ($c) => strtoupper((string) ($c['type'] ?? '')) === 'BODY')['text'] ?? '');
        $header = collect($components)->first(fn ($c) => strtoupper((string) ($c['type'] ?? '')) === 'HEADER');
        $buttons = $t['buttons'] ?? collect($components)->first(fn ($c) => strtoupper((string) ($c['type'] ?? '')) === 'BUTTONS')['buttons'] ?? [];
        if (is_string($buttons)) {
            $buttons = json_decode($buttons, true) ?: [];
        }
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $vars);

        return [
            'name' => (string) ($t['name'] ?? $t['template_name'] ?? $t['code_name'] ?? ''),
            'display_name' => (string) ($t['display_name'] ?? $t['name'] ?? ''),
            'language' => (string) ($t['language'] ?? $t['language_code'] ?? ''),
            'category' => strtoupper((string) ($t['category'] ?? '')),
            'status' => strtoupper((string) ($t['approval_status'] ?? $t['status'] ?? '')),
            'body' => mb_substr($body, 0, 1024),
            'variables' => count(array_unique($vars[1] ?? [])),
            'header' => strtolower((string) ($t['header_format'] ?? $header['format'] ?? 'none')) ?: 'none',
            'buttons' => collect(is_array($buttons) ? $buttons : [])->map(fn ($b) => [
                'type' => strtoupper((string) ($b['type'] ?? '')),
                'text' => (string) ($b['text'] ?? $b['title'] ?? ''),
            ])->filter(fn ($b) => $b['text'] !== '')->values()->all(),
        ];
    }

    /**
     * Interakt "message_template_status_update": a template was approved, rejected, paused…
     */
    public function statusUpdate(array $data): string
    {
        $name = (string) ($data['message_template_name'] ?? $data['template_name'] ?? $data['name'] ?? '');
        $status = strtoupper((string) ($data['event'] ?? $data['status'] ?? ''));
        if ($name === '' || $status === '') {
            return 'template status: incomplete';
        }
        $rows = WhatsAppTemplate::where('provider_name', $name)
            ->when(!empty($data['message_template_language']), fn ($q) => $q->where('language', $data['message_template_language']))
            ->get();
        foreach ($rows as $row) {
            $row->update(['approval_status' => mb_substr($status, 0, 20), 'synced_at' => now()]);
        }
        if ($status !== 'APPROVED' && $rows->isNotEmpty()) {
            $this->tellOwners('whatsapp_template', "WhatsApp template {$status}: {$name}", (string) ($data['reason'] ?? 'Check it in Interakt; messages of this kind go by share link meanwhile.'));
        }

        return "template {$status}";
    }

    /**
     * Account, quality and capability alerts from Interakt: a bell to the owner (once an hour per kind).
     */
    public function accountAlert(string $type, array $payload): string
    {
        $data = $payload['data'] ?? $payload['entry'] ?? $payload;
        $text = (string) ($data['alert_description'] ?? $data['event'] ?? $data['current_limit'] ?? $data['decision'] ?? json_encode($data));
        if (Cache::add("wa-alert:{$type}:" . md5($text), 1, now()->addHour())) {
            $this->tellOwners('whatsapp_alert', 'WhatsApp account: ' . str_replace('_', ' ', $type), mb_strimwidth($text, 0, 300, '…'));
        }

        return "alert: {$type}";
    }

    protected function tellOwners(string $kind, string $title, string $body): void
    {
        User::active()->whereHas('roles', fn ($q) => $q->where('slug', Role::OWNER))->get()
            ->each->notify(new StaffAlert($kind, $title, $body, '/crm/whatsapp/settings?tab=templates'));
    }
}
