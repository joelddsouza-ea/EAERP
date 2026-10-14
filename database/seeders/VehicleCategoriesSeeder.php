<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\VehicleCategory;

class VehicleCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Hatchback',
                'slug' => 'hatchback',
                'description' => 'Small hatchback cars like Swift, i10, Nano, Figo',
                'sort_order' => 1,
            ],
            [
                'name' => 'Premium Hatchback',
                'slug' => 'premium-hatchback',
                'description' => 'Premium hatchbacks like Jazz, i20, Polo',
                'sort_order' => 2,
            ],
            [
                'name' => 'Sedan / Compact SUV',
                'slug' => 'sedan-compact-suv',
                'description' => 'Sedans and compact SUVs like City, Verna, Creta, Venue',
                'sort_order' => 3,
            ],
            [
                'name' => 'SUV / MUV',
                'slug' => 'suv-muv',
                'description' => 'Full-size SUVs and MUVs like Innova, XUV, Safari',
                'sort_order' => 4,
            ],
            [
                'name' => 'Premium SUV',
                'slug' => 'premium-suv',
                'description' => 'Premium SUVs like Fortuner, Endeavour',
                'sort_order' => 5,
            ],
            [
                'name' => 'Luxury Class 3',
                'slug' => 'luxury-class-3',
                'description' => 'Entry luxury like Audi A4, BMW 3 Series, Mercedes C-Class',
                'sort_order' => 6,
            ],
            [
                'name' => 'Luxury Class 2',
                'slug' => 'luxury-class-2',
                'description' => 'Mid luxury like Audi A6, BMW 5 Series, Mercedes E-Class',
                'sort_order' => 7,
            ],
            [
                'name' => 'Luxury Class 1',
                'slug' => 'luxury-class-1',
                'description' => 'Top luxury like Audi A8, BMW 7 Series, Mercedes S-Class, Range Rover',
                'sort_order' => 8,
            ],
        ];

        foreach ($categories as $category) {
            VehicleCategory::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
