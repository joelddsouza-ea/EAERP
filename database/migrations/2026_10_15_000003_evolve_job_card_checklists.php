<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-job job-sheet rows: item status (done / N/A / not done), the ⚠ flag, and a
 * snapshot of the master rules so later edits to masters only affect new job cards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE job_card_checklists MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");

        Schema::table('job_card_checklists', function (Blueprint $table) {
            // History must survive a master template being removed
            $table->dropForeign(['template_id']);
        });

        Schema::table('job_card_checklists', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->change();
            $table->foreign('template_id')->references('id')->on('checklist_templates')->nullOnDelete();
            $table->string('sheet_kind', 30)->default('service_activities')->after('template_id');
            $table->string('name')->nullable()->after('sheet_kind');
            $table->timestamp('reopened_at')->nullable()->after('completed_at');
            $table->foreignId('reopened_by')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->string('reopen_notes', 500)->nullable()->after('reopened_by');
            $table->json('override_meta')->nullable()->after('reopen_notes');
        });

        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->dropForeign(['checklist_item_id']);
        });

        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            // Nullable for ad-hoc rows ("Others", extra panels)
            $table->unsignedBigInteger('checklist_item_id')->nullable()->change();
            $table->foreign('checklist_item_id')->references('id')->on('checklist_items')->nullOnDelete();

            $table->string('status', 16)->default('pending')->after('checklist_item_id');
            $table->boolean('needs_attention')->default(false)->after('status');
            $table->json('value_json')->nullable()->after('value');

            // Snapshot of the master rules at the time the sheet was created
            $table->string('section_name')->nullable()->after('needs_attention');
            $table->string('item_name')->nullable()->after('section_name');
            $table->string('input_type', 20)->default('tick')->after('item_name');
            $table->string('media_requirement', 16)->default('none')->after('input_type');
            $table->unsignedTinyInteger('min_media_count')->default(1)->after('media_requirement');
            $table->boolean('na_allowed')->default(false)->after('min_media_count');
            $table->string('expected_answer', 3)->nullable()->after('na_allowed');
            $table->boolean('is_required')->default(true)->after('expected_answer');
            $table->integer('sort_order')->default(0)->after('is_required');

            $table->index(['job_card_checklist_id', 'status'], 'jc_checklist_items_status_idx');
        });

        DB::transaction(function () {
            DB::statement("
                UPDATE job_card_checklists jcc
                JOIN checklist_templates t ON t.id = jcc.template_id
                SET jcc.sheet_kind = t.sheet_kind, jcc.name = t.name
            ");

            DB::statement("
                UPDATE job_card_checklist_items jci
                JOIN checklist_items ci ON ci.id = jci.checklist_item_id
                JOIN checklist_sections cs ON cs.id = ci.section_id
                SET jci.status = IF(jci.is_completed, 'done', 'pending'),
                    jci.section_name = cs.name,
                    jci.item_name = ci.item_name,
                    jci.input_type = ci.input_type,
                    jci.media_requirement = ci.media_requirement,
                    jci.min_media_count = ci.min_media_count,
                    jci.na_allowed = ci.na_allowed,
                    jci.expected_answer = ci.expected_answer,
                    jci.is_required = ci.is_required,
                    jci.sort_order = cs.sort_order * 1000 + ci.sort_order
            ");
        });
    }

    public function down(): void
    {
        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->dropIndex('jc_checklist_items_status_idx');
            $table->dropColumn([
                'status', 'needs_attention', 'value_json', 'section_name', 'item_name', 'input_type',
                'media_requirement', 'min_media_count', 'na_allowed', 'expected_answer', 'is_required', 'sort_order',
            ]);
            $table->dropForeign(['checklist_item_id']);
        });

        DB::table('job_card_checklist_items')->whereNull('checklist_item_id')->delete();

        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->unsignedBigInteger('checklist_item_id')->nullable(false)->change();
            $table->foreign('checklist_item_id')->references('id')->on('checklist_items')->cascadeOnDelete();
        });

        Schema::table('job_card_checklists', function (Blueprint $table) {
            $table->dropForeign(['reopened_by']);
            $table->dropColumn(['sheet_kind', 'name', 'reopened_at', 'reopened_by', 'reopen_notes', 'override_meta']);
            $table->dropForeign(['template_id']);
        });

        DB::table('job_card_checklists')->whereNull('template_id')->delete();

        Schema::table('job_card_checklists', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable(false)->change();
            $table->foreign('template_id')->references('id')->on('checklist_templates')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE job_card_checklists MODIFY status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending'");
    }
};
