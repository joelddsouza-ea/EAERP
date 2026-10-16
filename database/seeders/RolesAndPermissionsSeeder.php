<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Core\Models\Role;
use App\Core\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Define modules and their resources
        $modules = [
            'user-admin' => ['users', 'roles', 'permissions', 'branches'],
            'workshop' => ['customers', 'vehicles', 'job-cards', 'bays', 'technicians', 'checklists', 'spare-parts', 'qc', 'follow-ups'],
            'billing' => ['invoices', 'payments', 'credit-notes', 'reports'],
        ];

        $actions = ['view', 'create', 'update', 'delete'];

        // Create permissions
        foreach ($modules as $module => $resources) {
            foreach ($resources as $resource) {
                foreach ($actions as $action) {
                    Permission::firstOrCreate([
                        'slug' => "{$module}.{$resource}.{$action}",
                    ], [
                        'name' => ucfirst($action) . ' ' . str_replace('-', ' ', $resource),
                        'module' => $module,
                        'description' => ucfirst($action) . ' ' . str_replace('-', ' ', $resource),
                    ]);
                }
            }
        }

        // Create roles
        $roles = [
            [
                'name' => 'Owner',
                'slug' => Role::OWNER,
                'description' => 'Full access to all features across all branches',
                'is_system' => true,
            ],
            [
                'name' => 'Manager',
                'slug' => Role::MANAGER,
                'description' => 'Manages operations across assigned branches',
                'is_system' => true,
            ],
            [
                'name' => 'Service Advisor',
                'slug' => Role::SERVICE_ADVISOR,
                'description' => 'Manages service operations and technicians',
                'is_system' => true,
            ],
            [
                'name' => 'Technician',
                'slug' => Role::TECHNICIAN,
                'description' => 'Executes assigned jobs',
                'is_system' => true,
            ],
            [
                'name' => 'Backoffice Executive',
                'slug' => Role::BACKOFFICE,
                'description' => 'Handles billing, payments, and accounts',
                'is_system' => true,
            ],
            [
                'name' => 'Marketing Executive',
                'slug' => Role::MARKETING,
                'description' => 'Manages leads and customer relationships',
                'is_system' => true,
            ],
            [
                'name' => 'Body Shop Manager',
                'slug' => Role::BODY_SHOP_MANAGER,
                'description' => 'Manages body shop operations',
                'is_system' => true,
            ],
            [
                'name' => 'Body Shop Advisor',
                'slug' => Role::BODY_SHOP_ADVISOR,
                'description' => 'Manages body shop service operations',
                'is_system' => true,
            ],
            [
                'name' => 'Body Shop Technician',
                'slug' => Role::BODY_SHOP_TECHNICIAN,
                'description' => 'Executes body shop jobs',
                'is_system' => true,
            ],
            [
                'name' => 'Spare Parts Coordinator',
                'slug' => Role::SPARE_PARTS_COORDINATOR,
                'description' => 'Manages spare parts inventory and requests',
                'is_system' => true,
            ],
        ];

        foreach ($roles as $roleData) {
            Role::firstOrCreate(['slug' => $roleData['slug']], $roleData);
        }

        // Assign permissions to roles
        $this->assignRolePermissions();

        // Job-sheet / workflow permissions and the driver role
        $this->call(Phase1WorkflowPermissionsSeeder::class);

        // Estimates, customer approval, service report, price masters
        $this->call(Phase2EstimatePermissionsSeeder::class);

        // Parts pricing loop and suppliers
        $this->call(Phase2bPartsPermissionsSeeder::class);

        // Final bill, payments, gate pass
        $this->call(Phase3BillingPermissionsSeeder::class);

        // Purchases module: GRN, stock, supplier dues
        $this->call(PurchasesPermissionsSeeder::class);
    }

    protected function assignRolePermissions(): void
    {
        // Owner gets all permissions
        $owner = Role::where('slug', Role::OWNER)->first();
        $owner->permissions()->sync(Permission::pluck('id'));

        // Manager permissions
        $manager = Role::where('slug', Role::MANAGER)->first();
        $managerPerms = Permission::where(function ($q) {
            $q->where('module', 'workshop')
              ->orWhere('module', 'billing');
        })->orWhere('slug', 'like', 'user-admin.users.view')
          ->pluck('id');
        $manager->permissions()->sync($managerPerms);

        // Service Advisor permissions
        $advisor = Role::where('slug', Role::SERVICE_ADVISOR)->first();
        $advisorPerms = Permission::where(fn ($q) => $q->where('module', 'workshop')
                ->orWhereIn('slug', ['billing.invoices.view', 'billing.invoices.create']))
            ->whereNotIn('slug', [...Phase1WorkflowPermissionsSeeder::MANAGER_ONLY, ...Phase2EstimatePermissionsSeeder::MANAGER_ONLY])
            ->pluck('id');
        $advisor->permissions()->sync($advisorPerms);

        // Technician permissions
        $technician = Role::where('slug', Role::TECHNICIAN)->first();
        $techPerms = Permission::whereIn('slug', [
            'workshop.job-cards.view',
            'workshop.job-cards.update',
            'workshop.checklists.view',
            'workshop.checklists.update',
            'workshop.spare-parts.view',
            'workshop.spare-parts.create',
        ])->pluck('id');
        $technician->permissions()->sync($techPerms);

        // Backoffice permissions
        $backoffice = Role::where('slug', Role::BACKOFFICE)->first();
        $backofficePerms = Permission::where('module', 'billing')
            ->orWhereIn('slug', [
                'workshop.customers.view',
                'workshop.vehicles.view',
                'workshop.job-cards.view',
            ])
            ->pluck('id');
        $backoffice->permissions()->sync($backofficePerms);

        // Marketing permissions
        $marketing = Role::where('slug', Role::MARKETING)->first();
        $marketingPerms = Permission::whereIn('slug', [
            'workshop.customers.view',
            'workshop.customers.create',
            'workshop.customers.update',
            'workshop.follow-ups.view',
            'workshop.follow-ups.create',
            'workshop.follow-ups.update',
        ])->pluck('id');
        $marketing->permissions()->sync($marketingPerms);

        // Spare Parts Coordinator
        $spareParts = Role::where('slug', Role::SPARE_PARTS_COORDINATOR)->first();
        $sparePartsPerms = Permission::whereIn('slug', [
            'workshop.spare-parts.view',
            'workshop.spare-parts.create',
            'workshop.spare-parts.update',
            'workshop.job-cards.view',
        ])->pluck('id');
        $spareParts->permissions()->sync($sparePartsPerms);
    }
}
