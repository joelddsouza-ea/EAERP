<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * WhatsApp in the CRM (Phase 11): who answers chats, and who manages the Interakt connection,
 * templates and contact sync (was owner-only Settings › WhatsApp).
 * Idempotent and additive (syncWithoutDetaching).
 */
class Phase11WhatsAppPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'crm.whatsapp.inbox' => 'Answer WhatsApp chats in the CRM inbox (their leads and customers)',
        'crm.whatsapp.manage' => 'Manage the Interakt connection, templates, button actions and contact sync',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'module' => 'crm', 'description' => $name]);
        }

        $grants = [
            Role::OWNER => array_keys(self::PERMISSIONS),
            Role::MANAGER => ['crm.whatsapp.inbox'],
            Role::MARKETING => ['crm.whatsapp.inbox'],
            Role::SERVICE_ADVISOR => ['crm.whatsapp.inbox'],
            Role::BODY_SHOP_MANAGER => ['crm.whatsapp.inbox'],
            Role::BODY_SHOP_ADVISOR => ['crm.whatsapp.inbox'],
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
