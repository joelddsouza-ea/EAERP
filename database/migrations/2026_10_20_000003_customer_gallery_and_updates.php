<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section-wise photo/video gallery on the customer's page:
 * - photos/videos are visible to the customer unless an advisor hides one; final-QC
 *   (pre-delivery) evidence stays internal;
 * - the customer's page refreshes itself; the customer is told about new photos/videos
 *   only at chosen stages (Job stages master), clubbed into that stage's message;
 * - job_cards.gallery_notified_media_id: the last photo/video the customer was told about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_card_media', function (Blueprint $table) {
            $table->boolean('customer_visible')->default(true)->change();
        });

        // Everything taken so far is shown, except final-QC evidence
        DB::table('job_card_media')->update(['customer_visible' => true]);
        DB::statement("
            UPDATE job_card_media m
            JOIN job_card_checklist_items i ON m.attachable_type = 'sheet_item' AND i.id = m.attachable_id
            JOIN job_card_checklists s ON s.id = i.job_card_checklist_id
            SET m.customer_visible = 0
            WHERE s.sheet_kind = 'pre_delivery'
        ");

        Schema::table('job_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('gallery_notified_media_id')->nullable()->after('wash_done_at');
        });

        Schema::table('workflow_stages', function (Blueprint $table) {
            // Tell the customer about new photos/videos when the job enters this stage
            $table->boolean('gallery_update')->default(false)->after('message');
        });

        // Intake photos with "work started", the job-sheet work at the trial drive, the rest when ready
        DB::table('workflow_stages')
            ->whereIn('status', ['work_in_progress', 'post_work_trial', 'ready_for_delivery'])
            ->update(['gallery_update' => true]);
    }

    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropColumn('gallery_update');
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn('gallery_notified_media_id');
        });

        Schema::table('job_card_media', function (Blueprint $table) {
            $table->boolean('customer_visible')->default(false)->change();
        });
    }
};
