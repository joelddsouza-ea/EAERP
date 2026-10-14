<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            OrganizationSeeder::class,
            RolesAndPermissionsSeeder::class,
            VehicleCategoriesSeeder::class,
            VehicleMakesSeeder::class,
            OilTypesSeeder::class,
            ServicePackagesSeeder::class,
            ServiceAddonsSeeder::class,
            ChecklistTemplatesSeeder::class,
            TaxRatesSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
