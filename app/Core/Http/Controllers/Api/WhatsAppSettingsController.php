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
 * Settings › WhatsApp (owner): share links or Interakt, the API key, and which approved
 * template each kind of message goes out with.
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
        ]);

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
            ];
        })->values()->all();
    }
}
