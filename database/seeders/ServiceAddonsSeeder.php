<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\ServiceAddon;

class ServiceAddonsSeeder extends Seeder
{
    public function run(): void
    {
        $addons = [
            [
                'name' => 'Wheel Alignment & Balancing',
                'slug' => 'wheel-alignment-balancing',
                'description' => 'Complete wheel alignment and balancing service',
                'price' => 1000,
            ],
            [
                'name' => 'AC Gas Top-up',
                'slug' => 'ac-gas-topup',
                'description' => 'AC refrigerant gas top-up',
                'price' => 1500,
            ],
            [
                'name' => 'AC Full Service',
                'slug' => 'ac-full-service',
                'description' => 'Complete AC service with gas refill and cleaning',
                'price' => 3500,
            ],
            [
                'name' => 'Battery Replacement',
                'slug' => 'battery-replacement',
                'description' => 'Battery replacement (price excludes battery cost)',
                'price' => 500,
            ],
            [
                'name' => 'Brake Pad Replacement',
                'slug' => 'brake-pad-replacement',
                'description' => 'Front or rear brake pad replacement (price excludes pads)',
                'price' => 800,
            ],
            [
                'name' => 'Windshield Wiper Replacement',
                'slug' => 'wiper-replacement',
                'description' => 'Windshield wiper blade replacement',
                'price' => 300,
            ],
            [
                'name' => 'Headlight Restoration',
                'slug' => 'headlight-restoration',
                'description' => 'Headlight lens polishing and restoration',
                'price' => 1500,
            ],
            [
                'name' => 'Ceramic Coating',
                'slug' => 'ceramic-coating',
                'description' => 'Premium ceramic coating for paint protection',
                'price' => 15000,
            ],
            [
                'name' => 'Interior Deep Cleaning',
                'slug' => 'interior-deep-cleaning',
                'description' => 'Deep interior cleaning and sanitization',
                'price' => 2500,
            ],
            [
                'name' => 'Exterior Polishing',
                'slug' => 'exterior-polishing',
                'description' => 'Car exterior polishing and waxing',
                'price' => 2000,
            ],
            [
                'name' => 'Engine Flush',
                'slug' => 'engine-flush',
                'description' => 'Engine oil flush treatment',
                'price' => 800,
            ],
            [
                'name' => 'Fuel Injector Cleaning',
                'slug' => 'fuel-injector-cleaning',
                'description' => 'Fuel injector cleaning service',
                'price' => 1200,
            ],
        ];

        foreach ($addons as $addon) {
            ServiceAddon::firstOrCreate(
                ['slug' => $addon['slug']],
                $addon
            );
        }
    }
}
