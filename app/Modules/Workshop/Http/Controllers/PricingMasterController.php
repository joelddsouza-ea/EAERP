<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Models\LabourRate;
use App\Modules\Workshop\Models\LabourService;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackageLineItem;
use App\Modules\Workshop\Models\PackageLineItemPrice;
use App\Modules\Workshop\Models\PackageOilLitre;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Owner-editable price masters (formerly the Masters / Line Items tabs of the
 * estimate sheet). Every edit stamps customized_at so re-seeding keeps it.
 * Changes apply to estimates built afterwards; sent estimates keep their snapshot.
 */
class PricingMasterController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->success([
            'packages' => ServicePackage::withCount(['pricing', 'sheetTemplates'])
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (ServicePackage $p) => [
                    ...$p->only([
                        'id', 'name', 'slug', 'description', 'activity_points', 'home_service_available',
                        'send_customer_report', 'next_service_km', 'next_service_months', 'is_active', 'sort_order',
                    ]),
                    'features' => $p->features ?? [],
                    'prices_count' => $p->pricing_count,
                    'sheets_count' => $p->sheet_templates_count,
                ]),
            'categories' => VehicleCategory::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
            'oil_types' => OilType::where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name', 'slug', 'product_name', 'rate_per_litre']),
            'prices' => PackagePricing::get(['package_id', 'category_id', 'oil_type_id', 'price']),
            'litres' => PackageOilLitre::get(['package_id', 'category_id', 'litres']),
            'line_items' => PackageLineItem::with('prices:id,line_item_id,category_id,amount')
                ->orderBy('package_id')->orderBy('sort_order')
                ->get()
                ->map(fn (PackageLineItem $item) => [
                    'id' => $item->id,
                    'package_id' => $item->package_id,
                    'description' => $item->description,
                    'sort_order' => $item->sort_order,
                    'is_active' => $item->is_active,
                    'prices' => $item->prices->mapWithKeys(fn ($p) => [$p->category_id => (float) $p->amount]),
                ]),
            'labour_services' => LabourService::with('rates:id,labour_service_id,category_id,fuel_group,rate')
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (LabourService $service) => $this->presentLabourService($service)),
            'parts_default_margin_pct' => self::defaultPartsMargin(),
        ]);
    }

    /**
     * Default margin added on a part's GST-inclusive cost when it goes on an estimate.
     */
    public static function defaultPartsMargin(): float
    {
        return (float) WorkshopSettings::get('parts.default_margin_pct');
    }

    /**
     * Set a package price; null removes it (the combination is then not offered).
     */
    public function updatePrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', Rule::exists(ServicePackage::class, 'id')],
            'category_id' => ['required', Rule::exists(VehicleCategory::class, 'id')],
            'oil_type_id' => ['required', Rule::exists(OilType::class, 'id')],
            'price' => 'nullable|numeric|min:0',
        ]);

        $key = array_intersect_key($data, array_flip(['package_id', 'category_id', 'oil_type_id']));

        if ($data['price'] === null) {
            PackagePricing::where($key)->delete();

            return $this->success(null, 'Removed: not offered');
        }

        $row = PackagePricing::updateOrCreate($key, ['price' => $data['price'], 'customized_at' => now()]);

        return $this->success($row, 'Price saved');
    }

    public function updateLitres(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', Rule::exists(ServicePackage::class, 'id')],
            'category_id' => ['required', Rule::exists(VehicleCategory::class, 'id')],
            'litres' => 'required|numeric|min:0|max:50',
        ]);

        $row = PackageOilLitre::updateOrCreate(
            ['package_id' => $data['package_id'], 'category_id' => $data['category_id']],
            ['litres' => $data['litres'], 'customized_at' => now()]
        );

        return $this->success($row, 'Litres saved');
    }

    public function updateOilType(Request $request, OilType $oilType): JsonResponse
    {
        $data = $request->validate([
            'product_name' => 'sometimes|nullable|string|max:255',
            'rate_per_litre' => 'sometimes|numeric|min:0',
        ]);

        $oilType->update([...$data, 'customized_at' => now()]);

        return $this->success($oilType->only(['id', 'name', 'slug', 'product_name', 'rate_per_litre']), 'Oil grade saved');
    }

    /**
     * Add a new service plan. The slug is fixed at creation (seeders and job sheets key on it).
     */
    public function storePackage(Request $request): JsonResponse
    {
        $data = $request->validate($this->packageRules() + [
            'name' => 'required|string|max:100|unique:service_packages,name',
        ]);

        $slug = $base = Str::slug($data['name']);
        for ($i = 2; ServicePackage::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        $package = ServicePackage::create([
            'activity_points' => 0,
            'send_customer_report' => false,
            'home_service_available' => false,
            'is_active' => true,
            'next_service_km' => 10000,
            'next_service_months' => 9,
            ...$data,
            'slug' => $slug,
            'features' => $this->cleanFeatures($data['features'] ?? []),
            'sort_order' => (int) ServicePackage::max('sort_order') + 1,
            'customized_at' => now(),
        ]);

        return $this->created($package, 'Plan added');
    }

    /**
     * Edit a plan's details and settings. Plans are deactivated, never deleted (job cards refer to them).
     */
    public function updatePackage(Request $request, ServicePackage $package): JsonResponse
    {
        $data = $request->validate($this->packageRules() + [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('service_packages', 'name')->ignore($package->id)],
        ]);

        if (array_key_exists('features', $data)) {
            $data['features'] = $this->cleanFeatures($data['features'] ?? []);
        }

        $package->update([...$data, 'customized_at' => now()]);

        return $this->success($package->fresh(), 'Plan saved');
    }

    private function packageRules(): array
    {
        return [
            'description' => 'sometimes|nullable|string|max:2000',
            'features' => 'sometimes|nullable|array|max:50',
            'features.*' => 'nullable|string|max:255',
            'activity_points' => 'sometimes|integer|min:0|max:500',
            'home_service_available' => 'sometimes|boolean',
            'send_customer_report' => 'sometimes|boolean',
            'next_service_km' => 'sometimes|nullable|integer|min:0',
            'next_service_months' => 'sometimes|nullable|integer|min:0|max:60',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }

    private function cleanFeatures(?array $features): array
    {
        return array_values(array_filter(array_map(fn ($f) => trim((string) $f), $features ?? []), fn ($f) => $f !== ''));
    }

    public function storeLineItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', Rule::exists(ServicePackage::class, 'id')],
            'description' => [
                'required', 'string', 'max:255',
                Rule::unique('package_line_items')->where('package_id', $request->input('package_id')),
            ],
        ]);

        $item = PackageLineItem::create([
            ...$data,
            'sort_order' => (int) PackageLineItem::where('package_id', $data['package_id'])->max('sort_order') + 1,
            'is_active' => true,
            'customized_at' => now(),
        ]);

        return $this->created($item, 'Plan item added');
    }

    /**
     * Rename, (de)activate or reorder a plan item, and set its amount per car type.
     */
    public function updateLineItem(Request $request, PackageLineItem $lineItem): JsonResponse
    {
        $data = $request->validate([
            'description' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('package_line_items')->where('package_id', $lineItem->package_id)->ignore($lineItem->id),
            ],
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'prices' => 'sometimes|array',
            'prices.*' => 'numeric|min:0',
        ]);

        DB::transaction(function () use ($lineItem, $data) {
            $lineItem->update([...array_diff_key($data, ['prices' => 1]), 'customized_at' => now()]);

            foreach ($data['prices'] ?? [] as $categoryId => $amount) {
                if (!VehicleCategory::whereKey($categoryId)->exists()) {
                    continue;
                }
                PackageLineItemPrice::updateOrCreate(
                    ['line_item_id' => $lineItem->id, 'category_id' => $categoryId],
                    ['amount' => $amount, 'customized_at' => now()]
                );
            }
        });

        return $this->success($lineItem->fresh('prices'), 'Plan item saved');
    }

    /**
     * Remove a plan item. Estimates already built keep their own copy of the line.
     */
    public function destroyLineItem(PackageLineItem $lineItem): JsonResponse
    {
        $lineItem->delete();

        return $this->success(null, 'Plan item removed');
    }

    public function updatePartsMargin(Request $request): JsonResponse
    {
        $data = $request->validate(['default_margin_pct' => 'required|numeric|min:0|max:500']);

        WorkshopSettings::set('parts.default_margin_pct', (float) $data['default_margin_pct']);

        return $this->success(['parts_default_margin_pct' => self::defaultPartsMargin()], 'Parts margin saved');
    }

    public function storeLabourService(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:labour_services,name',
            'unit' => ['sometimes', Rule::in([LabourService::UNIT_JOB, LabourService::UNIT_HOUR])],
        ]);

        $service = LabourService::create([
            'name' => $data['name'],
            'unit' => $data['unit'] ?? LabourService::UNIT_JOB,
            'gst_rate' => 18,
            'sort_order' => (int) LabourService::max('sort_order') + 1,
            'is_active' => true,
            'customized_at' => now(),
        ]);

        return $this->created($this->presentLabourService($service->load('rates')), 'Labour service added');
    }

    /**
     * Rename, (de)activate or reorder a labour service, and set its rates:
     * rates[{fuel_group}][{category_id}] = amount, or null when not offered.
     */
    public function updateLabourService(Request $request, LabourService $labourService): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('labour_services', 'name')->ignore($labourService->id)],
            'unit' => ['sometimes', Rule::in([LabourService::UNIT_JOB, LabourService::UNIT_HOUR])],
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'rates' => 'sometimes|array',
            'rates.*' => 'array',
            'rates.*.*' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($labourService, $data) {
            $labourService->update([...array_diff_key($data, ['rates' => 1]), 'customized_at' => now()]);

            foreach ($data['rates'] ?? [] as $fuel => $byCategory) {
                if (!in_array($fuel, LabourRate::FUEL_GROUPS, true)) {
                    continue;
                }
                foreach ($byCategory as $categoryId => $rate) {
                    if (!VehicleCategory::whereKey($categoryId)->exists()) {
                        continue;
                    }
                    LabourRate::updateOrCreate(
                        ['labour_service_id' => $labourService->id, 'category_id' => $categoryId, 'fuel_group' => $fuel],
                        ['rate' => $rate, 'customized_at' => now()]
                    );
                }
            }
        });

        return $this->success($this->presentLabourService($labourService->fresh('rates')), 'Labour service saved');
    }

    private function presentLabourService(LabourService $service): array
    {
        $rates = [];
        foreach (LabourRate::FUEL_GROUPS as $fuel) {
            $rates[$fuel] = (object) $service->rates->where('fuel_group', $fuel)
                ->mapWithKeys(fn (LabourRate $r) => [$r->category_id => $r->rate === null ? null : (float) $r->rate])
                ->all();
        }

        return [
            'id' => $service->id,
            'name' => $service->name,
            'unit' => $service->unit,
            'gst_rate' => (float) $service->gst_rate,
            'sort_order' => $service->sort_order,
            'is_active' => $service->is_active,
            'rates' => $rates,
        ];
    }
}
