<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;
use App\Modules\Workshop\Models\VehicleCategory;

class CustomersVehiclesSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'name' => 'Rajesh Sharma',
                'phone' => '9876543210',
                'email' => 'rajesh.sharma@gmail.com',
                'address' => '15, Lokhandwala Complex',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400053',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Maruti Suzuki', 'model' => 'Swift', 'reg' => 'MH01AB1234', 'year' => 2021, 'color' => 'White', 'category' => 'hatchback'],
                    ['make' => 'Hyundai', 'model' => 'Creta', 'reg' => 'MH01CD5678', 'year' => 2023, 'color' => 'Black', 'category' => 'sedan-compact-suv'],
                ],
            ],
            [
                'name' => 'Priya Patel',
                'phone' => '9823456789',
                'email' => 'priya.patel@yahoo.com',
                'address' => '42, Bandra West',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400050',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Honda', 'model' => 'City', 'reg' => 'MH02EF9012', 'year' => 2022, 'color' => 'Silver', 'category' => 'sedan-compact-suv'],
                ],
            ],
            [
                'name' => 'Amit Desai',
                'phone' => '9812345678',
                'email' => 'amit.desai@outlook.com',
                'address' => '78, Juhu Beach Road',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400049',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Toyota', 'model' => 'Innova Crysta', 'reg' => 'MH01GH3456', 'year' => 2020, 'color' => 'Grey', 'category' => 'suv-muv'],
                ],
            ],
            [
                'name' => 'Sunita Reddy',
                'phone' => '9898765432',
                'email' => 'sunita.reddy@gmail.com',
                'address' => '23, Powai Lake Road',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400076',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'BMW', 'model' => '3 Series', 'reg' => 'MH01IJ7890', 'year' => 2022, 'color' => 'Blue', 'category' => 'luxury-class-3'],
                ],
            ],
            [
                'name' => 'Mohammed Khan',
                'phone' => '9867543210',
                'email' => 'mkhan@techcorp.com',
                'address' => '56, Andheri East',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400069',
                'customer_type' => 'corporate',
                'company_name' => 'TechCorp Solutions',
                'gstin' => '27AABCT1234A1Z5',
                'vehicles' => [
                    ['make' => 'Mahindra', 'model' => 'XUV700', 'reg' => 'MH03KL1234', 'year' => 2023, 'color' => 'Red', 'category' => 'premium-suv'],
                    ['make' => 'Tata', 'model' => 'Nexon', 'reg' => 'MH03MN5678', 'year' => 2022, 'color' => 'Orange', 'category' => 'sedan-compact-suv'],
                ],
            ],
            [
                'name' => 'Ananya Iyer',
                'phone' => '9845678901',
                'email' => 'ananya.iyer@gmail.com',
                'address' => '89, Versova',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400061',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Hyundai', 'model' => 'i20', 'reg' => 'MH02OP9012', 'year' => 2021, 'color' => 'Red', 'category' => 'premium-hatchback'],
                ],
            ],
            [
                'name' => 'Vikram Singh',
                'phone' => '9856789012',
                'email' => 'vikram.singh@gmail.com',
                'address' => '34, Goregaon West',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400062',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Mercedes-Benz', 'model' => 'E-Class', 'reg' => 'MH01QR3456', 'year' => 2021, 'color' => 'Black', 'category' => 'luxury-class-2'],
                ],
            ],
            [
                'name' => 'Deepika Menon',
                'phone' => '9834567890',
                'email' => 'deepika.m@gmail.com',
                'address' => '67, Malad West',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400064',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Kia', 'model' => 'Seltos', 'reg' => 'MH04ST7890', 'year' => 2023, 'color' => 'White', 'category' => 'sedan-compact-suv'],
                ],
            ],
            [
                'name' => 'Rahul Verma',
                'phone' => '9823456701',
                'email' => 'rahul.verma@fleet.com',
                'address' => '12, Kandivali East',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400101',
                'customer_type' => 'corporate',
                'company_name' => 'FleetMasters Pvt Ltd',
                'gstin' => '27AABCF5678B1Z3',
                'vehicles' => [
                    ['make' => 'Maruti Suzuki', 'model' => 'Dzire', 'reg' => 'MH04UV1234', 'year' => 2022, 'color' => 'Silver', 'category' => 'sedan-compact-suv'],
                    ['make' => 'Maruti Suzuki', 'model' => 'Ertiga', 'reg' => 'MH04WX5678', 'year' => 2022, 'color' => 'White', 'category' => 'suv-muv'],
                    ['make' => 'Toyota', 'model' => 'Fortuner', 'reg' => 'MH04YZ9012', 'year' => 2021, 'color' => 'Black', 'category' => 'premium-suv'],
                ],
            ],
            [
                'name' => 'Kavita Joshi',
                'phone' => '9812340987',
                'email' => 'kavita.joshi@gmail.com',
                'address' => '45, Borivali West',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400092',
                'customer_type' => 'individual',
                'vehicles' => [
                    ['make' => 'Volkswagen', 'model' => 'Polo', 'reg' => 'MH05AB3456', 'year' => 2020, 'color' => 'Blue', 'category' => 'premium-hatchback'],
                ],
            ],
        ];

        $makes = VehicleMake::pluck('id', 'name');
        $categories = VehicleCategory::pluck('id', 'slug');

        foreach ($customers as $customerData) {
            $vehicles = $customerData['vehicles'];
            unset($customerData['vehicles']);

            $customer = Customer::firstOrCreate(
                ['phone' => $customerData['phone']],
                $customerData
            );

            foreach ($vehicles as $vehicleData) {
                $make = VehicleMake::where('name', $vehicleData['make'])->first();
                $model = VehicleModel::where('name', $vehicleData['model'])->first();
                $category = VehicleCategory::where('slug', $vehicleData['category'])->first();

                if ($make && $category) {
                    Vehicle::firstOrCreate(
                        ['registration_no' => $vehicleData['reg']],
                        [
                            'customer_id' => $customer->id,
                            'make_id' => $make->id,
                            'model_id' => $model?->id,
                            'category_id' => $category->id,
                            'year' => $vehicleData['year'],
                            'color' => $vehicleData['color'],
                            'fuel_type' => 'petrol',
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }
}
