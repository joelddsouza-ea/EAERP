<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Pickup / drop and home service (Phase 5).
 *
 * Drivers see only their own trips (no job card list); technicians may be given home
 * visits. Idempotent and additive (syncWithoutDetaching), so hand-granted permissions
 * are kept — except the driver's job card list, which the trip screen replaces.
 */
class Phase5TripPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'workshop.trips.view' => 'View the pickups & drops board',
        'workshop.trips.manage' => 'Book, assign and cancel pickups, drops and home visits',
        'workshop.trips.drive' => 'Do pickups, drops and home visits given to me',
        'workshop.trips.settings' => 'Edit pickup / drop / home-visit charges, policy and photo minimums',
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
        $desk = ['workshop.trips.view', 'workshop.trips.manage'];
        $drive = ['workshop.trips.drive'];

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => $all,
            Role::BODY_SHOP_MANAGER => $desk,
            Role::SERVICE_ADVISOR => $desk,
            Role::BODY_SHOP_ADVISOR => $desk,
            Role::BACKOFFICE => ['workshop.trips.view'],
            Role::DRIVER => $drive,
            // Home visits are given to technicians
            Role::TECHNICIAN => $drive,
            Role::BODY_SHOP_TECHNICIAN => $drive,
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }

        // Drivers work from My Trips only
        Role::where('slug', Role::DRIVER)->first()?->permissions()
            ->detach(Permission::where('slug', 'workshop.job-cards.view')->pluck('id'));

        // Advisors book trips but neither drive nor edit the charges (an older seeder grants
        // advisors every workshop permission)
        foreach ([Role::SERVICE_ADVISOR, Role::BODY_SHOP_ADVISOR, Role::BODY_SHOP_MANAGER] as $roleSlug) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->detach(Permission::whereIn('slug', ['workshop.trips.settings', 'workshop.trips.drive'])->pluck('id'));
        }
    }
}
