<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Owner feedback 2026-10-24:
 *  - advisors may make, edit and approve the final bill (proforma) themselves, not only back office;
 *  - the tax invoice and closing on the proforma stay with back office, managers and the owner;
 *  - outside partners (washing, wheel alignment) and their login role;
 *  - "Send update": staff ask for the customer to be told; the advisor sends it.
 * Idempotent and additive (syncWithoutDetaching).
 */
class Phase12FloorBillingPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'billing.invoices.finalize' => 'Convert the final bill to a tax invoice, or close the job on the proforma',
        'workshop.partners.manage' => 'Manage outside service partners (washing, wheel alignment) and their logins',
        'workshop.outside-work.send' => 'Send a car to an outside partner and confirm it is back',
        'workshop.customer-updates.request' => 'Ask for a WhatsApp update to the customer after finishing a step',
        'workshop.partner.portal' => 'Outside partner: see the cars sent to them and upload photos/videos',
        'workshop.technician-time.view' => 'See how long technicians worked on jobs (Workshop › Technician Time)',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'module' => strtok($slug, '.'), 'description' => $name]);
        }
        Role::firstOrCreate(['slug' => Role::SERVICE_PARTNER], [
            'name' => 'Outside Service Partner',
            'description' => 'Washing / wheel alignment partner: the cars sent to them, photos and videos',
            'is_system' => true,
        ]);
        // Approving now means "send the bill to the customer for payment"
        Permission::where('slug', 'billing.proforma.approve')->update([
            'name' => 'Approve the final bill and send it to the customer for payment',
            'description' => 'Approve the final bill and send it to the customer for payment',
        ]);

        $update = 'workshop.customer-updates.request';
        $outside = 'workshop.outside-work.send';
        $advisor = ['billing.proforma.manage', 'billing.proforma.approve', $update, $outside];
        $manager = ['billing.invoices.finalize', 'workshop.partners.manage', 'workshop.technician-time.view', $update, $outside];
        $grants = [
            Role::OWNER => array_values(array_diff(array_keys(self::PERMISSIONS), ['workshop.partner.portal'])),
            Role::MANAGER => $manager,
            Role::BODY_SHOP_MANAGER => $manager,
            Role::BACKOFFICE => ['billing.invoices.finalize'],
            Role::SERVICE_ADVISOR => $advisor,
            Role::BODY_SHOP_ADVISOR => $advisor,
            Role::TECHNICIAN => [$update, $outside],
            Role::BODY_SHOP_TECHNICIAN => [$update, $outside],
            Role::DRIVER => [$update],
            Role::SERVICE_PARTNER => ['workshop.partner.portal'],
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
