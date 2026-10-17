<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groundwork for Phase 7 (CRM, leads, nurturing, next-service reminders):
 *  - each vehicle keeps its last service and when the next one is due (set when the car
 *    leaves, from the estimate or the plan's interval);
 *  - where a customer came from, and a slot for the lead they were converted from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('last_service_job_id')->nullable()->after('notes')->constrained('job_cards')->nullOnDelete();
            $table->date('last_service_at')->nullable()->after('last_service_job_id');
            $table->unsignedInteger('last_service_km')->nullable()->after('last_service_at');
            $table->date('next_service_due_date')->nullable()->after('last_service_km');
            $table->unsignedInteger('next_service_due_km')->nullable()->after('next_service_due_date');

            $table->index('next_service_due_date');
        });

        Schema::table('customers', function (Blueprint $table) {
            // walk_in | referral | online | lead | corporate | other
            $table->string('source', 20)->nullable()->after('customer_type');
            $table->unsignedBigInteger('lead_id')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['source', 'lead_id']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['next_service_due_date']);
            $table->dropConstrainedForeignId('last_service_job_id');
            $table->dropColumn(['last_service_at', 'last_service_km', 'next_service_due_date', 'next_service_due_km']);
        });
    }
};
