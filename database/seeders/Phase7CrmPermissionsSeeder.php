<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * CRM (Phase 7): leads, bookings, messages, insights, settings. Marketing executives and
 * advisors work leads and bookings (marketing first in the round-robin); managers and the
 * owner see and assign every lead. Idempotent and additive (syncWithoutDetaching).
 */
class Phase7CrmPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'crm.leads.view' => 'View leads',
        'crm.leads.work' => 'Work leads given to me (calls, quotes, follow-ups); receive new leads',
        'crm.leads.manage' => 'See and assign every lead, import leads',
        'crm.bookings.manage' => 'Make and manage bookings',
        'crm.messages.send' => 'Send WhatsApp messages from "Messages to send"',
        'crm.reports.view' => 'View CRM insights and feedback',
        'crm.settings.manage' => 'Edit CRM settings: sources, creatives, journeys, booking slots',
        'crm.campaigns.spend' => 'Enter ad spend per campaign',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'module' => 'crm', 'description' => $name]);
        }

        $work = ['crm.leads.view', 'crm.leads.work', 'crm.bookings.manage', 'crm.messages.send'];
        $grants = [
            Role::OWNER => array_keys(self::PERMISSIONS),
            Role::MANAGER => [...$work, 'crm.leads.manage', 'crm.reports.view', 'crm.settings.manage'],
            Role::BODY_SHOP_MANAGER => [...$work, 'crm.leads.manage', 'crm.reports.view'],
            Role::MARKETING => [...$work, 'workshop.vehicles.view'],
            Role::SERVICE_ADVISOR => $work,
            Role::BODY_SHOP_ADVISOR => $work,
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
