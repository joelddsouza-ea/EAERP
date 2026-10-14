<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\OilType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServicePackageController extends ApiController
{
    /**
     * List all service packages.
     */
    public function index(Request $request): JsonResponse
    {
        $packages = ServicePackage::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($package) {
                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'slug' => $package->slug,
                    'activity_points' => $package->activity_points,
                    'description' => $package->description,
                    'features' => $package->features,
                    'home_service_available' => $package->home_service_available,
                ];
            });

        return $this->success($packages);
    }

    /**
     * Get a specific package with full details.
     */
    public function show(ServicePackage $package): JsonResponse
    {
        $package->load(['checklistTemplates.sections.items']);

        return $this->success([
            'id' => $package->id,
            'name' => $package->name,
            'slug' => $package->slug,
            'activity_points' => $package->activity_points,
            'description' => $package->description,
            'features' => $package->features,
            'home_service_available' => $package->home_service_available,
            'checklist_templates' => $package->checklistTemplates,
        ]);
    }

    /**
     * Get pricing matrix for a package.
     */
    public function pricing(Request $request, ServicePackage $package): JsonResponse
    {
        // If specific category and oil type provided, return single price
        if ($request->has('category_id') && $request->has('oil_type_id')) {
            $price = $package->getPriceFor(
                $request->integer('category_id'),
                $request->integer('oil_type_id')
            );

            return $this->success([
                'package_id' => $package->id,
                'category_id' => $request->integer('category_id'),
                'oil_type_id' => $request->integer('oil_type_id'),
                'price' => $price,
            ]);
        }

        // Otherwise return full pricing matrix
        $categories = VehicleCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $oilTypes = OilType::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $pricing = PackagePricing::where('package_id', $package->id)->get();

        $matrix = [];
        foreach ($categories as $category) {
            $categoryPricing = [];
            foreach ($oilTypes as $oilType) {
                $price = $pricing
                    ->where('category_id', $category->id)
                    ->where('oil_type_id', $oilType->id)
                    ->first();

                $categoryPricing[$oilType->slug] = $price?->price;
            }
            $matrix[$category->slug] = [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                ],
                'prices' => $categoryPricing,
            ];
        }

        return $this->success([
            'package' => [
                'id' => $package->id,
                'name' => $package->name,
            ],
            'oil_types' => $oilTypes->map(fn($o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
            ]),
            'pricing' => $matrix,
        ]);
    }
}
