<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM › WhatsApp settings (permission crm.whatsapp.manage; was Settings › WhatsApp): share
 * links or Interakt, the API key and webhook signing secret, the CRM switches (new chats
 * become leads, contact / event sync, assign in Interakt), which approved template each kind
 * of message goes out with, and what a quick-reply tap does.
 */
class WhatsAppSettingsController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->success($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'required|in:share,interakt',
            'api_key' => 'nullable|string|max:500',
            'clear_api_key' => 'boolean',
            'signing_secret' => 'nullable|string|max:200',
            'clear_signing_secret' => 'boolean',
            'crm' => 'sometimes|array',
            'crm.auto_lead' => 'boolean',
            'crm.sync_contacts' => 'boolean',
            'crm.sync_events' => 'boolean',
            'crm.assign_in_interakt' => 'boolean',
        ]);

        if (!empty($data['clear_signing_secret'])) {
            WhatsAppSettings::setSigningSecret(null);
        } elseif (!empty($data['signing_secret'])) {
            WhatsAppSettings::setSigningSecret($data['signing_secret']);
        }
        foreach ($data['crm'] ?? [] as $key => $on) {
            \App\Core\Support\WorkshopSettings::set("crm.whatsapp.{$key}", (bool) $on);
        }

        if (!empty($data['clear_api_key'])) {
            WhatsAppSettings::setApiKey(null);
        } elseif (!empty($data['api_key'])) {
            WhatsAppSettings::setApiKey($data['api_key']);
        }

        if ($data['provider'] === WhatsAppSettings::INTERAKT && WhatsAppSettings::apiKey() === null) {
            return $this->error('Enter the Interakt API key before switching it on.', 422, ['api_key' => ['Required to switch Interakt on.']]);
        }
        WhatsAppSettings::setProvider($data['provider']);

        return $this->success($this->state(), $data['provider'] === WhatsAppSettings::INTERAKT ? 'Interakt switched on' : 'Share links in use (Interakt off)');
    }

    /**
     * Send one approved template to a number, to check the key and the template.
     */
    public function test(Request $request, InteraktChannel $interakt): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'template' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language' => 'nullable|string|max:10',
            'values' => 'array|max:10',
            'values.*' => 'nullable|string|max:500',
        ]);
        $phone = ShareLinkChannel::normalizePhone($data['phone']);
        if (!$phone) {
            return $this->error('Enter a valid Indian mobile number.', 422, ['phone' => ['Not a valid mobile number.']]);
        }

        $values = array_map(fn ($v) => trim((string) $v) === '' ? '-' : (string) $v, $data['values'] ?? []);
        $result = $interakt->sendTemplate($phone, $data['template'], $data['language'] ?? 'en', $values);

        return $result['ok']
            ? $this->success(['id' => $result['id']], 'Test message sent')
            : $this->error($result['error'] ?? 'Not sent.', 422);
    }

    public function templates(): JsonResponse
    {
        return $this->success($this->templateRows());
    }

    public function saveTemplates(Request $request): JsonResponse
    {
        $catalog = WhatsAppTemplate::catalog();
        $data = $request->validate([
            'templates' => 'required|array',
            'templates.*.key' => ['required', 'string', Rule::in(array_keys($catalog))],
            'templates.*.provider_name' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'templates.*.language' => 'nullable|string|max:10',
            'templates.*.body_params' => 'nullable|array|max:20',
            'templates.*.body_params.*' => 'nullable|string|max:40',
            'templates.*.header_type' => ['nullable', Rule::in(WhatsAppTemplate::HEADER_TYPES)],
            'templates.*.button_param' => 'nullable|string|max:40',
            'templates.*.is_active' => 'boolean',
            // Quick-reply button text => lead action
            'templates.*.button_actions' => 'nullable|array|max:3',
            'templates.*.button_actions.*' => ['nullable', Rule::in(array_keys(\App\Modules\Crm\Services\WhatsApp\WhatsAppButtonService::ACTIONS))],
        ], ['templates.*.provider_name.regex' => 'Template names in Interakt are lower case letters, digits and _ only.']);

        foreach ($data['templates'] as $row) {
            WhatsAppTemplate::updateOrCreate(['key' => $row['key']], [
                'label' => $catalog[$row['key']]['label'],
                'provider_name' => ($row['provider_name'] ?? '') !== '' ? $row['provider_name'] : null,
                'language' => ($row['language'] ?? '') ?: 'en',
                'body_params' => array_values(array_filter($row['body_params'] ?? [], fn ($p) => trim((string) $p) !== '')),
                'header_type' => $row['header_type'] ?? 'none',
                'button_param' => ($row['button_param'] ?? '') ?: null,
                'is_active' => $row['is_active'] ?? true,
                'button_actions' => collect($row['button_actions'] ?? [])->filter()->mapWithKeys(fn ($a, $text) => [mb_substr(trim((string) $text), 0, 25) => $a])->all() ?: null,
            ]);
        }

        return $this->success($this->templateRows(), 'Templates saved');
    }

    protected function state(): array
    {
        return [
            'provider' => WhatsAppSettings::provider(),
            'api_on' => WhatsAppSettings::apiOn(),
            'api_key_masked' => WhatsAppSettings::maskedKey(),
            'webhook_url' => WhatsAppSettings::webhookUrl(),
            'enabled_at' => WhatsAppSettings::enabledAt(),
            'mapped' => WhatsAppTemplate::where('is_active', true)->whereNotNull('provider_name')->where('provider_name', '!=', '')->count(),
            'signing_secret_set' => WhatsAppSettings::signingSecret() !== null,
            'crm' => [
                'auto_lead' => (bool) \App\Core\Support\WorkshopSettings::get('crm.whatsapp.auto_lead'),
                'sync_contacts' => (bool) \App\Core\Support\WorkshopSettings::get('crm.whatsapp.sync_contacts'),
                'sync_events' => (bool) \App\Core\Support\WorkshopSettings::get('crm.whatsapp.sync_events'),
                'assign_in_interakt' => (bool) \App\Core\Support\WorkshopSettings::get('crm.whatsapp.assign_in_interakt'),
            ],
            // Which webhooks have arrived lately: chats only come on Interakt's higher plans
            'webhooks' => \App\Modules\Crm\Models\InteraktWebhookEvent::selectRaw('type, MAX(created_at) AS last_at, COUNT(*) AS n')
                ->where('created_at', '>', now()->subDays(30))->groupBy('type')->orderByDesc('last_at')->limit(30)->get()
                ->map(fn ($r) => ['type' => $r->type, 'last_at' => \Illuminate\Support\Carbon::parse($r->last_at)->toIso8601String(), 'count_30d' => (int) $r->n])->values(),
            'webhook_errors' => \App\Modules\Crm\Models\InteraktWebhookEvent::whereNotNull('error')->latest('id')->limit(5)->get(['type', 'error', 'created_at'])
                ->map(fn ($r) => ['type' => $r->type, 'error' => $r->error, 'at' => $r->created_at?->toIso8601String()])->values(),
        ];
    }

    /**
     * Every kind of message with its current text, the text to submit to Interakt
     * ({{1}}, {{2}} …) and the saved mapping.
     */
    protected function templateRows(): array
    {
        $saved = WhatsAppTemplate::all()->keyBy('key');

        return collect(WhatsAppTemplate::catalog())->map(function ($item, $key) use ($saved) {
            $row = $saved[$key] ?? null;
            $suggested = WhatsAppTemplate::placeholders($item['text']);
            $params = $row?->body_params ?: $suggested;

            return [
                'key' => $key,
                'label' => $item['label'],
                'group' => $item['group'],
                'text' => $item['text'],
                'suggested_params' => $suggested,
                'provider_text' => WhatsAppTemplate::providerText($item['text'], $params),
                'provider_name' => $row?->provider_name,
                'language' => $row?->language ?? 'en',
                'body_params' => $params,
                'header_type' => $row?->header_type ?? $item['header'],
                'button_param' => $row?->button_param,
                'is_active' => $row?->is_active ?? true,
                'mapped' => (bool) $row?->isMapped(),
                // As Interakt reports it (fetch / webhook)
                'approval_status' => $row?->approval_status,
                'category' => $row?->category,
                'buttons' => $row?->buttons ?? [],
                'button_actions' => $row?->button_actions ?? (object) [],
            ];
        })->values()->all();
    }
}
