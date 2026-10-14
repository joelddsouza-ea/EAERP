<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\OilType;

class OilTypesSeeder extends Seeder
{
    public function run(): void
    {
        $oilTypes = [
            [
                'name' => 'Semi-Synthetic',
                'slug' => 'semi-synthetic',
                'description' => 'Standard semi-synthetic engine oil',
                'price_multiplier' => 1.00,
                'sort_order' => 1,
            ],
            [
                'name' => 'Fully Synthetic',
                'slug' => 'fully-synthetic',
                'description' => 'Premium fully synthetic engine oil',
                'price_multiplier' => 1.15,
                'sort_order' => 2,
            ],
            [
                'name' => 'Top-Tier Synthetic',
                'slug' => 'top-tier-synthetic',
                'description' => 'Top-tier synthetic oil for luxury vehicles',
                'price_multiplier' => 1.30,
                'sort_order' => 3,
            ],
        ];

        foreach ($oilTypes as $oilType) {
            OilType::firstOrCreate(
                ['slug' => $oilType['slug']],
                $oilType
            );
        }
    }
}
