<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Permissions for estimates, customer approval, customer links / messages, the
 * service report and the price masters.
 *
 * Idempotent and additive (syncWithoutDetaching), so permissions an admin has
 * granted by hand are kept.
 */
class Phase2EstimatePermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'workshop.estimates.view' => 'View estimates',
        'workshop.estimates.manage' => 'Prepare and edit estimates',
        'workshop.estimates.send' => 'Send estimates to customers',
        'workshop.estimates.decide-on-behalf' => 'Record approvals on the customer\'s behalf',
        'workshop.reports.view' => 'View service reports',
        'workshop.reports.release' => 'Release service reports to customers',
        'workshop.customer-links.manage' => 'Manage customer links and messages',
        'workshop.pricing-masters.view' => 'View price masters',
        'workshop.pricing-masters.update' => 'Edit price masters',
    ];

    /**
     * Never granted to advisors by the module-wide assignment in RolesAndPermissionsSeeder.
     */
    public const MANAGER_ONLY = [
        'workshop.pricing-masters.update',
    ];

    private const ADVISOR = [
        'workshop.estimates.view',
        'workshop.estimates.manage',
        'workshop.estimates.send',
        'workshop.estimates.decide-on-behalf',
        'workshop.reports.view',
        'workshop.reports.release',
        'workshop.customer-links.manage',
        'workshop.pricing-masters.view',
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

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => $all,
            Role::BODY_SHOP_MANAGER => $all,
            Role::SERVICE_ADVISOR => self::ADVISOR,
            Role::BODY_SHOP_ADVISOR => self::ADVISOR,
            Role::BACKOFFICE => ['workshop.estimates.view', 'workshop.reports.view', 'workshop.pricing-masters.view'],
            Role::SPARE_PARTS_COORDINATOR => ['workshop.estimates.view'],
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }

        foreach ([Role::SERVICE_ADVISOR, Role::BODY_SHOP_ADVISOR] as $roleSlug) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->detach(Permission::whereIn('slug', self::MANAGER_ONLY)->pluck('id'));
        }
    }
}
