<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job sheet (owner, 2026-10-24):
 *  - petrol-only points (spark plugs) are N/A and locked on a diesel / electric car;
 *  - "if applicable / if required / if needed" points are shown in another colour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->boolean('petrol_only')->default(false)->after('workshop_only');
            $table->boolean('conditional')->default(false)->after('petrol_only');
        });
        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            // Not for this car (e.g. spark plugs on a diesel): N/A and can't be changed
            $table->boolean('locked')->default(false)->after('is_required');
            $table->boolean('conditional')->default(false)->after('locked');
        });

        // Seed the flags on the existing masters by name (the owner can change them)
        DB::table('checklist_items')->where('item_name', 'like', '%spark plug%')->update(['petrol_only' => true]);
        DB::table('checklist_items')->where(fn ($q) => $q->where('item_name', 'like', '%if applicable%')
            ->orWhere('item_name', 'like', '%if required%')->orWhere('item_name', 'like', '%if needed%'))
            ->update(['conditional' => true]);
        // Open jobs get the colour too
        DB::table('job_card_checklist_items')->where(fn ($q) => $q->where('item_name', 'like', '%if applicable%')
            ->orWhere('item_name', 'like', '%if required%')->orWhere('item_name', 'like', '%if needed%'))
            ->update(['conditional' => true]);
    }

    public function down(): void
    {
        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->dropColumn(['locked', 'conditional']);
        });
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn(['petrol_only', 'conditional']);
        });
    }
};
