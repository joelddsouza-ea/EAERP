<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Leads from the customer app (accident help, enquiries from unknown numbers) get their own source.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('lead_sources')->where('slug', 'customer_app')->exists()) {
            return;
        }
        DB::table('lead_sources')->insert([
            'name' => 'Customer app',
            'slug' => 'customer_app',
            'is_paid' => false,
            'webhook_key' => Str::random(32),
            'is_active' => true,
            'sort_order' => (int) DB::table('lead_sources')->max('sort_order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('lead_sources')->where('slug', 'customer_app')->delete();
    }
};
