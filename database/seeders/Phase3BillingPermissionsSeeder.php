<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Billing to delivery: back office makes and approves the final bill (proforma),
 * advisors and back office take payments, owner/manager may let a car go before
 * full payment (workshop.job-cards.override-guards).
 *
 * Idempotent and additive (syncWithoutDetaching), so hand-granted permissions are kept.
 */
class Phase3BillingPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'billing.proforma.manage' => 'Make and edit the final bill (proforma)',
        'billing.proforma.approve' => 'Approve the final bill, convert it to a tax invoice or close it',
        // Existing since the first release; listed so a fresh database gets them
        'billing.invoices.view' => 'View invoices',
        'billing.payments.create' => 'Record payments',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'module' => 'billing',
                'description' => $name,
            ]);
        }

        $all = array_keys(self::PERMISSIONS);
        $advisor = ['billing.invoices.view', 'billing.payments.create'];

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => $all,
            Role::BODY_SHOP_MANAGER => $all,
            Role::BACKOFFICE => $all,
            Role::SERVICE_ADVISOR => $advisor,
            Role::BODY_SHOP_ADVISOR => $advisor,
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
