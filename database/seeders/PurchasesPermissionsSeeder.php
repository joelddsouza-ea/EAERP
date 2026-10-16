<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\StockItem;
use Illuminate\Database\Seeder;

/**
 * Purchases module (Phase 3): GRN, consumables stock, supplier dues, the module switch.
 * Also gives each engine-oil grade its stock item, so plan kits can issue oil by litres.
 *
 * Idempotent and additive (syncWithoutDetaching), so hand-granted permissions are kept.
 */
class PurchasesPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'purchases.settings.manage' => 'Switch the purchases & stock module on or off',
        'purchases.grn.view' => 'View GRNs and the purchase register',
        'purchases.grn.create' => 'Receive parts on a GRN, return parts to suppliers',
        'purchases.grn.cancel' => 'Cancel a GRN',
        'purchases.stock.view' => 'View stock',
        'purchases.stock.issue' => 'Issue stock and plan kits to jobs',
        'purchases.stock.adjust' => 'Adjust stock after a count',
        'purchases.stock-items.manage' => 'Edit stock items and plan kits',
        'purchases.suppliers.pay' => 'Record payments to suppliers',
        'purchases.job-cost.view' => "See a job's parts cost",
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'module' => 'purchases',
                'description' => $name,
            ]);
        }

        $all = array_keys(self::PERMISSIONS);
        $advisor = ['purchases.grn.view', 'purchases.stock.view', 'purchases.stock.issue'];

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => $all,
            Role::BODY_SHOP_MANAGER => array_values(array_diff($all, ['purchases.settings.manage'])),
            Role::SPARE_PARTS_COORDINATOR => [
                'purchases.grn.view', 'purchases.grn.create', 'purchases.grn.cancel',
                'purchases.stock.view', 'purchases.stock.issue', 'purchases.stock-items.manage',
            ],
            Role::BACKOFFICE => ['purchases.grn.view', 'purchases.stock.view', 'purchases.suppliers.pay', 'purchases.job-cost.view'],
            Role::SERVICE_ADVISOR => $advisor,
            Role::BODY_SHOP_ADVISOR => $advisor,
        ];

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }

        // One stock item per engine-oil grade (litres), used by plan kits
        foreach (OilType::all() as $oil) {
            StockItem::firstOrCreate(['oil_type_id' => $oil->id], [
                'name' => ($oil->product_name ?: $oil->name . ' engine oil'),
                'category' => 'oil',
                'unit' => 'ltr',
                'hsn_code' => '27101980',
                'gst_rate' => 18,
                'reorder_level' => 20,
            ]);
        }
    }
}
