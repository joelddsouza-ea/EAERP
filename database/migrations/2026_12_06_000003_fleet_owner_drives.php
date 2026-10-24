<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet: the owner drives some cabs himself (owner, 2026-10-24) — one login, no driver limit,
 * the other cabs keep their drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('owner_cab_ids')->nullable()->after('fleet_driver_limit');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('owner_cab_ids');
        });
    }
};
