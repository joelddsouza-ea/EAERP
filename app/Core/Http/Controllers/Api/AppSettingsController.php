<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Support\PublicUrl;
use App\Core\Support\WorkshopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings › App address & links and Settings › Customer app (owner): the address phones and
 * customers reach the ERP at, and the customer app's UPI details and switches.
 */
class AppSettingsController extends ApiController
{
    public const SETTINGS = [
        'app.public_url' => ['nullable', 'string', 'max:200', 'regex:#^https?://[^\s/]+(:\d+)?(/[^\s]*)?$#'],
        'customer_app.upi_id' => ['nullable', 'string', 'max:100', 'regex:/^[\w.\-]{2,}@[a-zA-Z][\w.\-]*$/'],
        'customer_app.upi_name' => ['nullable', 'string', 'max:100'],
        'customer_app.support_phone' => ['nullable', 'string', 'max:20'],
        'customer_app.otp_minutes' => ['integer', 'min:3', 'max:30'],
        'customer_app.push_enabled' => ['boolean'],
    ];

    /**
     * Public: lets the staff app's first-run screen check it found an EA Workshop server.
     */
    public function ping(): JsonResponse
    {
        return $this->success(['app' => 'eaerp', 'name' => config('app.name'), 'time' => now()->toIso8601String()]);
    }

    public function show(): JsonResponse
    {
        return $this->success($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (self::SETTINGS as $key => $rule) {
            $rules[str_replace('.', '__', $key)] = ['sometimes', ...$rule];
        }
        $input = [];
        foreach ($request->input('settings', []) as $key => $value) {
            $input[str_replace('.', '__', $key)] = is_string($value) ? trim($value) : $value;
        }
        $data = validator($input, $rules, [
            'app__public_url.regex' => 'Enter the full address, e.g. https://erp.expressautocare.in or http://192.168.1.20:8000',
            'customer_app__upi_id.regex' => 'Enter a UPI ID like expressautocare@okicici',
        ])->validate();

        foreach ($data as $key => $value) {
            $key = str_replace('__', '.', $key);
            if ($key === 'app.public_url' && $value) {
                $value = rtrim($value, '/');
            }
            WorkshopSettings::set($key, $value === '' ? null : $value);
        }

        return $this->success($this->state(), 'Saved');
    }

    protected function state(): array
    {
        $settings = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            $settings[$key] = WorkshopSettings::get($key);
        }

        return [
            'settings' => $settings,
            // What links use right now (this server's address while none is set)
            'effective_url' => PublicUrl::base() ?? rtrim(url('/'), '/'),
            'customer_app_url' => PublicUrl::to('/app'),
        ];
    }
}
