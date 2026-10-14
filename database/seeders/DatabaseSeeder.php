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
            // Core data
            OrganizationSeeder::class,
            RolesAndPermissionsSeeder::class,

            // Master data
            VehicleCategoriesSeeder::class,
            VehicleMakesSeeder::class,
            OilTypesSeeder::class,
            ServicePackagesSeeder::class,
            ServiceAddonsSeeder::class,
            ChecklistTemplatesSeeder::class,
            JobSheetTemplatesSeeder::class,
            TaxRatesSeeder::class,

            // Users
            AdminUserSeeder::class,

            // Workshop data
            BaysSeeder::class,
            CustomersVehiclesSeeder::class,
            JobCardsSeeder::class,
        ]);
    }
}
