<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Models\User;
use App\Core\Models\Role;
use App\Core\Models\Branch;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin/owner user
        $owner = User::firstOrCreate(
            ['email' => 'admin@expressautocare.in'],
            [
                'name' => 'Admin User',
                'phone' => '+919876543210',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        // Assign owner role
        $ownerRole = Role::where('slug', Role::OWNER)->first();
        $owner->roles()->syncWithoutDetaching([$ownerRole->id]);

        // Assign to all branches
        $branches = Branch::all();
        $branchData = [];
        foreach ($branches as $index => $branch) {
            $branchData[$branch->id] = ['is_primary' => $index === 0];
        }
        $owner->branches()->sync($branchData);

        // Create sample users for each role
        $sampleUsers = [
            [
                'name' => 'Branch Manager',
                'email' => 'manager@expressautocare.in',
                'phone' => '+919876543211',
                'role' => Role::MANAGER,
            ],
            [
                'name' => 'Service Advisor',
                'email' => 'advisor@expressautocare.in',
                'phone' => '+919876543212',
                'role' => Role::SERVICE_ADVISOR,
            ],
            [
                'name' => 'Technician One',
                'email' => 'tech1@expressautocare.in',
                'phone' => '+919876543213',
                'role' => Role::TECHNICIAN,
            ],
            [
                'name' => 'Technician Two',
                'email' => 'tech2@expressautocare.in',
                'phone' => '+919876543214',
                'role' => Role::TECHNICIAN,
            ],
            [
                'name' => 'Backoffice Staff',
                'email' => 'backoffice@expressautocare.in',
                'phone' => '+919876543215',
                'role' => Role::BACKOFFICE,
            ],
            [
                'name' => 'Parts Coordinator',
                'email' => 'parts@expressautocare.in',
                'phone' => '+919876543216',
                'role' => Role::SPARE_PARTS_COORDINATOR,
            ],
            [
                'name' => 'Driver One',
                'email' => 'driver@expressautocare.in',
                'phone' => '+919876543217',
                'role' => Role::DRIVER,
            ],
            [
                'name' => 'Marketing Executive',
                'email' => 'marketing@expressautocare.in',
                'phone' => '+919876543218',
                'role' => Role::MARKETING,
            ],
        ];

        $firstBranch = Branch::first();

        foreach ($sampleUsers as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'phone' => $userData['phone'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                ]
            );

            // Assign role
            $role = Role::where('slug', $userData['role'])->first();
            $user->roles()->syncWithoutDetaching([$role->id]);

            // Assign to first branch
            $user->branches()->syncWithoutDetaching([
                $firstBranch->id => ['is_primary' => true],
            ]);
        }
    }
}
