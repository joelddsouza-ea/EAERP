<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Support\Purchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings › Modules: the owner switches optional modules on or off.
 */
class ModulesController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success($this->state());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchases' => 'sometimes|boolean',
            'accounts' => 'sometimes|boolean',
            'crm' => 'sometimes|boolean',
            'hr' => 'sometimes|boolean',
            'customer_app' => 'sometimes|boolean',
        ]);

        foreach ($data as $module => $on) {
            WorkshopSettings::set($module === 'purchases' ? 'purchases.enabled' : "modules.{$module}", (bool) $on);
        }

        return $this->success($this->state(), 'Modules saved');
    }

    protected function state(): array
    {
        return [
            'purchases' => Purchases::enabled(),
            ...collect(Modules::SWITCHABLE)->mapWithKeys(fn ($label, $key) => [$key => Modules::enabled($key)])->all(),
        ];
    }
}
