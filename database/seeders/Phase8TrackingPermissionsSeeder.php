<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Live staff location (Phase 8): the owner and the managers see the live map and trails.
 * Idempotent and additive (syncWithoutDetaching).
 */
class Phase8TrackingPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'hr.tracking.view' => "See staff live location and each day's trail",
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'module' => 'hr', 'description' => $name]);
        }

        $grants = [
            Role::OWNER => array_keys(self::PERMISSIONS),
            Role::MANAGER => ['hr.tracking.view'],
            Role::BODY_SHOP_MANAGER => ['hr.tracking.view'],
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
