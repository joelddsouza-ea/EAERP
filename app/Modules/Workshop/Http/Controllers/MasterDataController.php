<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServiceAddon;
use Illuminate\Http\JsonResponse;

class MasterDataController extends ApiController
{
    /**
     * Get all vehicle makes.
     */
    public function vehicleMakes(): JsonResponse
    {
        $makes = VehicleMake::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        return $this->success($makes);
    }

    /**
     * Get models for a make.
     */
    public function vehicleModels(VehicleMake $make): JsonResponse
    {
        $models = $make->models()
            ->where('is_active', true)
            ->with('defaultCategory:id,name,slug')
            ->orderBy('name')
            ->get(['id', 'name', 'default_category_id']);

        return $this->success($models);
    }

    /**
     * Get all vehicle categories.
     */
    public function vehicleCategories(): JsonResponse
    {
        $categories = VehicleCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'description']);

        return $this->success($categories);
    }

    /**
     * Get all oil types.
     */
    public function oilTypes(): JsonResponse
    {
        $oilTypes = OilType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'description', 'price_multiplier']);

        return $this->success($oilTypes);
    }

    /**
     * Get all service addons.
     */
    public function serviceAddons(): JsonResponse
    {
        $addons = ServiceAddon::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'price']);

        return $this->success($addons);
    }
}
