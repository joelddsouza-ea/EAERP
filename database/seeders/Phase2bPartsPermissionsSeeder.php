<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Parts pricing loop and suppliers: advisors ask for prices, the spare parts
 * coordinator quotes and orders, back office keeps the supplier list.
 *
 * Idempotent and additive (syncWithoutDetaching), so hand-granted permissions are kept.
 */
class Phase2bPartsPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'workshop.suppliers.view' => 'View suppliers',
        'workshop.suppliers.manage' => 'Add and edit suppliers',
        // Existing since the first release; listed so a fresh database gets them
        'workshop.spare-parts.view' => 'View spare part requests',
        'workshop.spare-parts.create' => 'Ask for spare part prices',
        'workshop.spare-parts.update' => 'Quote and order spare parts',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'module' => 'workshop',
                'description' => $name,
            ]);
        }

        $all = array_keys(self::PERMISSIONS);
        $advisor = ['workshop.suppliers.view', 'workshop.spare-parts.view', 'workshop.spare-parts.create'];

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => $all,
            Role::BODY_SHOP_MANAGER => $all,
            Role::SPARE_PARTS_COORDINATOR => $all,
            Role::BACKOFFICE => ['workshop.suppliers.view', 'workshop.suppliers.manage', 'workshop.spare-parts.view'],
            Role::SERVICE_ADVISOR => $advisor,
            Role::BODY_SHOP_ADVISOR => $advisor,
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
