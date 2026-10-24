<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Fleets (Phase 10): who handles the problems drivers report from the app, and who edits the
 * Fleet Cabs master (driver approval amount).
 * Idempotent and additive (syncWithoutDetaching).
 */
class Phase10FleetPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'workshop.fleet.reports' => 'See and handle problems reported by fleet drivers (book in, pickup, close)',
        'workshop.fleet.settings' => 'Edit Masters › Fleet Cabs (driver approval amount per job, service-due warning)',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'module' => 'workshop', 'description' => $name]);
        }

        $grants = [
            Role::OWNER => array_keys(self::PERMISSIONS),
            Role::MANAGER => ['workshop.fleet.reports', 'workshop.fleet.settings'],
            Role::SERVICE_ADVISOR => ['workshop.fleet.reports'],
            Role::BODY_SHOP_MANAGER => ['workshop.fleet.reports', 'workshop.fleet.settings'],
            Role::BODY_SHOP_ADVISOR => ['workshop.fleet.reports'],
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
