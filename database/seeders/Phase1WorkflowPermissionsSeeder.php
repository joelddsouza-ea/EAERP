<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Permissions for the job-card workflow and digital job sheets, plus the driver role.
 *
 * Idempotent and additive (syncWithoutDetaching), so permissions an admin has
 * granted by hand are kept.
 */
class Phase1WorkflowPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'workshop.job-cards.change-status' => 'Change job card status',
        'workshop.job-cards.override-guards' => 'Override workflow checks',
        'workshop.job-sheets.view' => 'View job sheets',
        'workshop.job-sheets.update' => 'Fill job sheets',
        'workshop.job-sheets.complete' => 'Submit job sheets',
        'workshop.job-sheets.reopen' => 'Reopen submitted job sheets',
        'workshop.job-sheets.manage' => 'Add or sync job sheets on a job card',
        'workshop.job-sheets.delete-any-media' => 'Delete any job-sheet media',
        'workshop.job-sheet-masters.view' => 'View job-sheet masters',
        'workshop.job-sheet-masters.create' => 'Create job-sheet masters',
        'workshop.job-sheet-masters.update' => 'Edit job-sheet masters',
        'workshop.pre-delivery.view' => 'View pre-delivery checklist',
        'workshop.pre-delivery.update' => 'Fill pre-delivery checklist',
    ];

    /**
     * Never granted to advisors by the module-wide assignment in RolesAndPermissionsSeeder.
     */
    public const MANAGER_ONLY = [
        'workshop.job-cards.override-guards',
        'workshop.job-sheets.reopen',
        'workshop.job-sheet-masters.view',
        'workshop.job-sheet-masters.create',
        'workshop.job-sheet-masters.update',
    ];

    private const TECHNICIAN = [
        'workshop.job-sheets.view',
        'workshop.job-sheets.update',
        'workshop.job-sheets.complete',
    ];

    private const ADVISOR = [
        'workshop.job-cards.change-status',
        'workshop.job-sheets.view',
        'workshop.job-sheets.update',
        'workshop.job-sheets.complete',
        'workshop.job-sheets.manage',
        'workshop.job-sheets.delete-any-media',
        'workshop.pre-delivery.view',
        'workshop.pre-delivery.update',
        'workshop.qc.view',
        'workshop.qc.update',
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

        Role::firstOrCreate(['slug' => Role::DRIVER], [
            'name' => 'Driver',
            'description' => 'Picks up and drops customer cars',
            'is_system' => true,
        ]);

        $all = array_keys(self::PERMISSIONS);

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => $all,
            Role::BODY_SHOP_MANAGER => [...$all, 'workshop.job-cards.view', 'workshop.job-cards.update', 'workshop.qc.view', 'workshop.qc.update'],
            Role::SERVICE_ADVISOR => self::ADVISOR,
            Role::BODY_SHOP_ADVISOR => [...self::ADVISOR, 'workshop.job-cards.view', 'workshop.job-cards.update'],
            Role::TECHNICIAN => self::TECHNICIAN,
            Role::BODY_SHOP_TECHNICIAN => [...self::TECHNICIAN, 'workshop.job-cards.view', 'workshop.job-cards.update'],
            Role::SPARE_PARTS_COORDINATOR => ['workshop.job-sheets.view'],
            Role::BACKOFFICE => ['workshop.job-sheets.view', 'workshop.pre-delivery.view'],
            // Pickup/drop permissions arrive with the pickup module
            Role::DRIVER => ['workshop.job-cards.view'],
        ];

        foreach ($grants as $roleSlug => $slugs) {
            $role = Role::where('slug', $roleSlug)->first();
            if (!$role) {
                continue;
            }
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }

        // Advisors must not hold manager-only permissions (an older seeder granted all workshop permissions)
        foreach ([Role::SERVICE_ADVISOR, Role::BODY_SHOP_ADVISOR] as $roleSlug) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->detach(Permission::whereIn('slug', self::MANAGER_ONLY)->pluck('id'));
        }
    }
}
