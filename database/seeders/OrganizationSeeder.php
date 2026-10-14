<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Models\Organization;
use App\Core\Models\Branch;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        // Create organization
        $org = Organization::create([
            'name' => 'Express Autocare',
            'legal_name' => 'Express Autocare Pvt. Ltd.',
            'address' => '123 Auto Street',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400001',
            'phone' => '+91 22 12345678',
            'email' => 'info@expressautocare.com',
            'gstin' => '27AABCE1234F1Z5',
            'pan' => 'AABCE1234F',
            'settings' => [
                'currency' => 'INR',
                'timezone' => 'Asia/Kolkata',
                'date_format' => 'd/m/Y',
                'gst_rate' => 18,
            ],
        ]);

        // Create branches
        $branches = [
            [
                'organization_id' => $org->id,
                'name' => 'Andheri Branch',
                'code' => 'ANH',
                'address' => '456 Link Road, Andheri West',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400053',
                'phone' => '+91 22 26123456',
                'email' => 'andheri@expressautocare.com',
                'gstin' => '27AABCE1234F1Z5',
                'is_active' => true,
            ],
            [
                'organization_id' => $org->id,
                'name' => 'Bandra Branch',
                'code' => 'BND',
                'address' => '789 Hill Road, Bandra West',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400050',
                'phone' => '+91 22 26543210',
                'email' => 'bandra@expressautocare.com',
                'gstin' => '27AABCE1234F1Z5',
                'is_active' => true,
            ],
        ];

        foreach ($branches as $branch) {
            Branch::create($branch);
        }
    }
}
