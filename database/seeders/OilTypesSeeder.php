<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\OilType;

class OilTypesSeeder extends Seeder
{
    public function run(): void
    {
        $priceList = require database_path('seeders/data/pricing/price_list.php');

        foreach ($priceList['oil_types'] as $oilType) {
            $existing = OilType::where('slug', $oilType['slug'])->first();

            // Product and rate edited in the app are kept
            if ($existing?->customized_at) {
                continue;
            }

            OilType::updateOrCreate(['slug' => $oilType['slug']], [...$oilType, 'is_active' => true]);
        }
    }
}
