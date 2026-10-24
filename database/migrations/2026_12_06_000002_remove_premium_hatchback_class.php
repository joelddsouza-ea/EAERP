<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Premium Hatchback" goes for good (owner, 2026-10-24): it was merged into
 * "Prem. Hatch / Sedan / Compact SUV" on 2026-10-16 but still showed in some lists.
 * Cars, models, leads and estimates move to the merged class; its own price rows go.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('vehicle_categories')->where('slug', 'premium-hatchback')->value('id');
        $new = DB::table('vehicle_categories')->where('slug', 'sedan-compact-suv')->value('id');
        if (!$old) {
            return;
        }

        if ($new) {
            DB::table('vehicles')->where('category_id', $old)->update(['category_id' => $new]);
            DB::table('vehicle_models')->where('default_category_id', $old)->update(['default_category_id' => $new]);
            foreach (['job_card_estimates', 'leads'] as $table) {
                if (DB::getSchemaBuilder()->hasColumn($table, 'category_id')) {
                    DB::table($table)->where('category_id', $old)->update(['category_id' => $new]);
                }
            }
        }

        // Price, oil, labour and kit rows of the old class (the merged class has its own)
        foreach (['package_pricing', 'package_oil_litres', 'package_line_item_prices', 'labour_rates', 'plan_kit_items'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table) && DB::getSchemaBuilder()->hasColumn($table, 'category_id')) {
                DB::table($table)->where('category_id', $old)->delete();
            }
        }

        DB::table('vehicle_categories')->where('id', $old)->delete();
    }

    public function down(): void
    {
        // Not restored: the class was retired by the owner
    }
};
