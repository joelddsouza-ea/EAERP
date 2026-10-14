<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\OilType;

class ServicePackagesSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name' => 'EA Refresh',
                'slug' => 'ea-refresh',
                'activity_points' => 18,
                'description' => 'Quick top-up & clean-up between services',
                'features' => [
                    'Engine oil top-up (up to 500ml)',
                    'Coolant top-up',
                    'Windshield washer top-up',
                    'Tyre pressure check',
                    'Exterior wash',
                    'Interior vacuum',
                    'Dashboard wipe',
                ],
                'home_service_available' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'EA Essentials',
                'slug' => 'ea-essentials',
                'activity_points' => 25,
                'description' => 'Regular oil-change with essentials',
                'features' => [
                    'Engine oil change',
                    'Oil filter replacement',
                    'Air filter cleaning',
                    'AC filter check',
                    'All fluid top-up',
                    'Battery check',
                    'Brake inspection',
                    'Exterior wash',
                    'Interior cleaning',
                ],
                'home_service_available' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'EA Pro',
                'slug' => 'ea-pro',
                'activity_points' => 45,
                'description' => 'Full mechanical + engine, brakes, wheels, cabin',
                'features' => [
                    'All EA Essentials features',
                    'Spark plug inspection',
                    'Fuel filter check',
                    'Brake pad inspection',
                    'Wheel alignment check',
                    'Suspension inspection',
                    'Steering check',
                    'Complete under-body inspection',
                    'Belt inspection',
                    'Exhaust system check',
                ],
                'home_service_available' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'EA 360',
                'slug' => 'ea-360',
                'activity_points' => 70,
                'description' => 'Deep health check - every system inspected',
                'features' => [
                    'All EA Pro features',
                    'Complete diagnostic scan',
                    'AC performance check',
                    'Electrical system check',
                    'Clutch inspection',
                    'Transmission fluid check',
                    'Power steering fluid',
                    'Detailed inspection report',
                    'Roadside inspection',
                ],
                'home_service_available' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'EA Elite',
                'slug' => 'ea-elite',
                'activity_points' => 80,
                'description' => 'Full service + full interior/exterior detailing',
                'features' => [
                    'All EA 360 features',
                    'Exterior detailing',
                    'Interior deep cleaning',
                    'Leather conditioning',
                    'Engine bay cleaning',
                    'Alloy wheel cleaning',
                    'Windshield treatment',
                    'Paint protection',
                ],
                'home_service_available' => false,
                'sort_order' => 5,
            ],
        ];

        // Base prices for Hatchback with Semi-Synthetic oil
        $basePrices = [
            'ea-refresh' => 1499,
            'ea-essentials' => 2499,
            'ea-pro' => 4999,
            'ea-360' => 7999,
            'ea-elite' => 12999,
        ];

        // Category multipliers
        $categoryMultipliers = [
            'hatchback' => 1.0,
            'premium-hatchback' => 1.15,
            'sedan-compact-suv' => 1.25,
            'suv-muv' => 1.40,
            'premium-suv' => 1.60,
            'luxury-class-3' => 2.0,
            'luxury-class-2' => 2.5,
            'luxury-class-1' => 3.0,
        ];

        $categories = VehicleCategory::pluck('id', 'slug');
        $oilTypes = OilType::get();

        foreach ($packages as $packageData) {
            $package = ServicePackage::firstOrCreate(
                ['slug' => $packageData['slug']],
                $packageData
            );

            // Create pricing matrix
            $basePrice = $basePrices[$packageData['slug']];

            foreach ($categories as $categorySlug => $categoryId) {
                $categoryMultiplier = $categoryMultipliers[$categorySlug] ?? 1.0;

                foreach ($oilTypes as $oilType) {
                    $price = round($basePrice * $categoryMultiplier * $oilType->price_multiplier, -1); // Round to nearest 10

                    PackagePricing::firstOrCreate(
                        [
                            'package_id' => $package->id,
                            'category_id' => $categoryId,
                            'oil_type_id' => $oilType->id,
                        ],
                        ['price' => $price]
                    );
                }
            }
        }
    }
}
