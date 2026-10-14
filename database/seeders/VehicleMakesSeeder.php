<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;
use App\Modules\Workshop\Models\VehicleCategory;

class VehicleMakesSeeder extends Seeder
{
    public function run(): void
    {
        $categories = VehicleCategory::pluck('id', 'slug');

        $makes = [
            'Maruti Suzuki' => [
                'Alto' => 'hatchback',
                'Swift' => 'hatchback',
                'Wagon R' => 'hatchback',
                'Baleno' => 'premium-hatchback',
                'Dzire' => 'sedan-compact-suv',
                'Ciaz' => 'sedan-compact-suv',
                'Brezza' => 'sedan-compact-suv',
                'Ertiga' => 'suv-muv',
                'XL6' => 'suv-muv',
                'Grand Vitara' => 'suv-muv',
            ],
            'Hyundai' => [
                'Grand i10' => 'hatchback',
                'i20' => 'premium-hatchback',
                'Verna' => 'sedan-compact-suv',
                'Venue' => 'sedan-compact-suv',
                'Creta' => 'sedan-compact-suv',
                'Alcazar' => 'suv-muv',
                'Tucson' => 'premium-suv',
            ],
            'Honda' => [
                'Jazz' => 'premium-hatchback',
                'Amaze' => 'sedan-compact-suv',
                'City' => 'sedan-compact-suv',
                'Elevate' => 'sedan-compact-suv',
            ],
            'Tata' => [
                'Tiago' => 'hatchback',
                'Altroz' => 'premium-hatchback',
                'Nexon' => 'sedan-compact-suv',
                'Harrier' => 'suv-muv',
                'Safari' => 'suv-muv',
            ],
            'Mahindra' => [
                'XUV300' => 'sedan-compact-suv',
                'XUV700' => 'suv-muv',
                'Scorpio' => 'suv-muv',
                'Thar' => 'suv-muv',
            ],
            'Toyota' => [
                'Glanza' => 'premium-hatchback',
                'Urban Cruiser' => 'sedan-compact-suv',
                'Innova' => 'suv-muv',
                'Innova Crysta' => 'suv-muv',
                'Fortuner' => 'premium-suv',
                'Land Cruiser' => 'luxury-class-1',
            ],
            'Kia' => [
                'Sonet' => 'sedan-compact-suv',
                'Seltos' => 'sedan-compact-suv',
                'Carens' => 'suv-muv',
            ],
            'Volkswagen' => [
                'Polo' => 'premium-hatchback',
                'Virtus' => 'sedan-compact-suv',
                'Taigun' => 'sedan-compact-suv',
            ],
            'Skoda' => [
                'Slavia' => 'sedan-compact-suv',
                'Kushaq' => 'sedan-compact-suv',
                'Superb' => 'luxury-class-3',
            ],
            'MG' => [
                'Astor' => 'sedan-compact-suv',
                'Hector' => 'suv-muv',
                'Gloster' => 'premium-suv',
            ],
            'BMW' => [
                '2 Series' => 'luxury-class-3',
                '3 Series' => 'luxury-class-3',
                '5 Series' => 'luxury-class-2',
                '7 Series' => 'luxury-class-1',
                'X1' => 'luxury-class-3',
                'X3' => 'luxury-class-2',
                'X5' => 'luxury-class-1',
                'X7' => 'luxury-class-1',
            ],
            'Mercedes-Benz' => [
                'A-Class' => 'luxury-class-3',
                'C-Class' => 'luxury-class-3',
                'E-Class' => 'luxury-class-2',
                'S-Class' => 'luxury-class-1',
                'GLA' => 'luxury-class-3',
                'GLC' => 'luxury-class-2',
                'GLE' => 'luxury-class-1',
                'GLS' => 'luxury-class-1',
            ],
            'Audi' => [
                'A3' => 'luxury-class-3',
                'A4' => 'luxury-class-3',
                'A6' => 'luxury-class-2',
                'A8' => 'luxury-class-1',
                'Q3' => 'luxury-class-3',
                'Q5' => 'luxury-class-2',
                'Q7' => 'luxury-class-1',
                'Q8' => 'luxury-class-1',
            ],
            'Land Rover' => [
                'Discovery Sport' => 'luxury-class-2',
                'Range Rover Evoque' => 'luxury-class-2',
                'Range Rover Velar' => 'luxury-class-1',
                'Range Rover Sport' => 'luxury-class-1',
                'Range Rover' => 'luxury-class-1',
            ],
            'Jaguar' => [
                'XE' => 'luxury-class-3',
                'XF' => 'luxury-class-2',
                'F-Pace' => 'luxury-class-2',
            ],
            'Volvo' => [
                'XC40' => 'luxury-class-3',
                'XC60' => 'luxury-class-2',
                'XC90' => 'luxury-class-1',
                'S60' => 'luxury-class-3',
                'S90' => 'luxury-class-2',
            ],
        ];

        foreach ($makes as $makeName => $models) {
            $make = VehicleMake::firstOrCreate(['name' => $makeName]);

            foreach ($models as $modelName => $categorySlug) {
                VehicleModel::firstOrCreate(
                    [
                        'make_id' => $make->id,
                        'name' => $modelName,
                    ],
                    [
                        'default_category_id' => $categories[$categorySlug] ?? null,
                    ]
                );
            }
        }
    }
}
