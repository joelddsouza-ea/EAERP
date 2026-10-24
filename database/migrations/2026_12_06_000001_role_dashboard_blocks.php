<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles & Access › Dashboard: which dashboard blocks each role sees (owner, 2026-10-24).
 * Null = the defaults (owner / managers / back office see the money, others only their work).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('dashboard_blocks')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('dashboard_blocks');
        });
    }
};
