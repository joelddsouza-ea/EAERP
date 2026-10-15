<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technicians ask for parts against an additional job line. Their parts start as
 * "draft", become "proposed" when they tap "Submit to SA for Parts", and the advisor
 * forwards them to the spare parts coordinator ("requested"). Received parts are
 * marked by the coordinator until the Phase 3 GRN replaces that step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->foreignId('job_card_additional_job_id')->nullable()->after('estimate_line_key')
                ->constrained('job_card_additional_jobs')->nullOnDelete();
            $table->foreignId('forwarded_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('forwarded_at')->nullable()->after('forwarded_by');
        });
    }

    public function down(): void
    {
        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_card_additional_job_id');
            $table->dropConstrainedForeignId('forwarded_by');
            $table->dropColumn('forwarded_at');
        });
    }
};
