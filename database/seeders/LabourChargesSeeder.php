<?php

namespace Database\Seeders;

use App\Modules\Workshop\Models\LabourRate;
use App\Modules\Workshop\Models\LabourService;
use App\Modules\Workshop\Models\VehicleCategory;
use Illuminate\Database\Seeder;

/**
 * Labour charges schedule from the owner's chart. Rows edited in the app
 * (customized_at set) are never overwritten.
 */
class LabourChargesSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('seeders/data/pricing/labour_charges.php');
        $categories = VehicleCategory::pluck('id', 'slug');

        foreach ($data['services'] as $index => $row) {
            [$name, $unit] = $row;

            $service = LabourService::withTrashed()->firstOrNew(['name' => $name]);
            if (!$service->customized_at) {
                $service->fill(['unit' => $unit, 'gst_rate' => 18, 'sort_order' => $index + 1, 'is_active' => true])->save();
            }

            foreach ($data['columns'] as $column => [$categorySlugs, $fuelGroups]) {
                foreach ($categorySlugs as $slug) {
                    if (!isset($categories[$slug])) {
                        continue;
                    }
                    foreach ($fuelGroups as $fuel) {
                        $rate = LabourRate::firstOrNew([
                            'labour_service_id' => $service->id,
                            'category_id' => $categories[$slug],
                            'fuel_group' => $fuel,
                        ]);
                        if (!$rate->customized_at) {
                            $rate->rate = $row[$column];
                            $rate->save();
                        }
                    }
                }
            }
        }
    }
}
