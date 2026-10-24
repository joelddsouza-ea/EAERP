<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\VehicleCategory;

class VehicleCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Names and order follow the owner's estimate sheet; descriptions are ours
        $descriptions = [
            'hatchback' => 'Small hatchback cars like Swift, i10, Nano, Figo',
            'sedan-compact-suv' => 'Premium hatchbacks, sedans and compact SUVs like i20, Jazz, City, Verna, Creta, Venue',
            'suv-muv' => 'Full-size SUVs and MUVs like Innova, XUV, Safari',
            'premium-suv' => 'Premium SUVs like Fortuner, Endeavour',
            'luxury-class-1' => 'Top luxury like Audi A8, BMW 7 Series, Mercedes S-Class, Range Rover',
            'luxury-class-2' => 'Mid luxury like Audi A6, BMW 5 Series, Mercedes E-Class',
            'luxury-class-3' => 'Entry luxury like Audi A4, BMW 3 Series, Mercedes C-Class',
        ];

        $priceList = require database_path('seeders/data/pricing/price_list.php');

        foreach ($priceList['categories'] as $category) {
            $row = VehicleCategory::firstOrNew(['slug' => $category['slug']]);
            // Classes edited in Price Masters › Car classes are kept
            if (!$row->customized_at) {
                $row->fill([...$category, 'description' => $descriptions[$category['slug']] ?? null, 'is_active' => true])->save();
            }
        }

        // "Premium Hatchback" was merged into "Prem. Hatch / Sedan / Compact SUV" and removed (2026_12_06_000002)
    }
}
