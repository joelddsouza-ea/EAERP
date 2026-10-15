<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Car classification master (Price Masters › Car classes & models): the classes
 * prices are set by, and which class each car model belongs to — picked up
 * automatically when a vehicle's model is chosen.
 */
class CarClassController extends ApiController
{
    public function index(): JsonResponse
    {
        $priced = PackagePricing::select('category_id')->distinct()->pluck('category_id')->all();

        return $this->success([
            'classes' => VehicleCategory::withCount('vehicles')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (VehicleCategory $c) => [
                    ...$c->only(['id', 'name', 'slug', 'description', 'sort_order', 'is_active']),
                    'vehicles_count' => $c->vehicles_count,
                    'has_prices' => in_array($c->id, $priced, true),
                ]),
            'makes' => VehicleMake::orderBy('name')->get(['id', 'name', 'is_active']),
            'models' => VehicleModel::with('make:id,name')->orderBy('make_id')->orderBy('name')->get()
                ->map(fn (VehicleModel $m) => [
                    'id' => $m->id,
                    'make_id' => $m->make_id,
                    'make' => $m->make?->name,
                    'name' => $m->name,
                    'class_id' => $m->default_category_id,
                    'is_active' => $m->is_active,
                ]),
        ]);
    }

    public function storeClass(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:vehicle_categories,name',
            'description' => 'nullable|string|max:500',
        ]);

        $slug = $base = Str::slug($data['name']);
        for ($i = 2; VehicleCategory::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        $class = VehicleCategory::create([
            ...$data,
            'slug' => $slug,
            'sort_order' => (int) VehicleCategory::max('sort_order') + 1,
            'is_active' => true,
            'customized_at' => now(),
        ]);

        return $this->created($class, 'Car class added — set its prices in Package prices, Plan items, Engine oil and Labour charges');
    }

    public function updateClass(Request $request, VehicleCategory $vehicleCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('vehicle_categories', 'name')->ignore($vehicleCategory->id)],
            'description' => 'sometimes|nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $vehicleCategory->update([...$data, 'customized_at' => now()]);

        return $this->success($vehicleCategory, 'Car class saved');
    }

    public function storeMake(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100|unique:vehicle_makes,name']);

        return $this->created(VehicleMake::create([...$data, 'is_active' => true]), 'Make added');
    }

    public function storeModel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'make_id' => ['required', Rule::exists('vehicle_makes', 'id')],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('vehicle_models', 'name')->where('make_id', $request->input('make_id'))->whereNull('deleted_at'),
            ],
            'class_id' => ['required', Rule::exists('vehicle_categories', 'id')],
        ]);

        $model = VehicleModel::create([
            'make_id' => $data['make_id'],
            'name' => $data['name'],
            'default_category_id' => $data['class_id'],
            'is_active' => true,
            'customized_at' => now(),
        ]);

        return $this->created($model, 'Car model added');
    }

    public function updateModel(Request $request, VehicleModel $vehicleModel): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('vehicle_models', 'name')->where('make_id', $vehicleModel->make_id)->whereNull('deleted_at')->ignore($vehicleModel->id),
            ],
            'class_id' => ['sometimes', 'required', Rule::exists('vehicle_categories', 'id')],
            'is_active' => 'sometimes|boolean',
        ]);

        if (array_key_exists('class_id', $data)) {
            $data['default_category_id'] = $data['class_id'];
            unset($data['class_id']);
        }

        $vehicleModel->update([...$data, 'customized_at' => now()]);

        return $this->success($vehicleModel, 'Car model saved');
    }
}
