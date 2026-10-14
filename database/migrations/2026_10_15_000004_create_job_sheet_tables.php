<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job-sheet companions: activity media (photo/video), observations, customer-reported
 * issues, additional jobs done, and body-shop insurance details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_card_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            // sheet_item | issue | additional_job (morph map aliases)
            $table->nullableMorphs('attachable');
            $table->string('media_type', 10); // photo | video
            $table->string('disk', 30);
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->decimal('duration_seconds', 6, 2)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('description')->nullable();
            $table->boolean('customer_visible')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['job_card_id', 'media_type']);
        });

        Schema::create('job_card_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_checklist_id')->nullable()->constrained('job_card_checklists')->nullOnDelete();
            $table->string('source', 20)->default('manual'); // manual | activity | pre_delivery
            // Sheet item that raised it (⚠ flag); unique so toggling updates one row
            $table->foreignId('source_item_id')->nullable()->unique()->constrained('job_card_checklist_items')->cascadeOnDelete();
            $table->text('text');
            $table->integer('sort_order')->default(0);
            $table->boolean('customer_visible')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('job_card_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->text('description');
            $table->string('priority', 10)->default('normal'); // critical | high | normal
            $table->string('status', 15)->default('open'); // open | in_progress | resolved | deferred
            $table->text('resolution_notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['job_card_id', 'status']);
        });

        Schema::create('job_card_additional_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_issue_id')->nullable()->constrained('job_card_issues')->nullOnDelete();
            $table->text('description');
            $table->text('parts_used')->nullable();
            $table->decimal('cost', 10, 2)->default(0);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            // Set when pushed to billing in a later phase
            $table->foreignId('job_card_item_id')->nullable()->constrained('job_card_items')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('job_card_insurance_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('insurance_company')->nullable();
            $table->string('claim_no', 100)->nullable();
            $table->date('survey_date')->nullable();
            $table->string('surveyor_name')->nullable();
            $table->string('surveyor_mobile', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_card_insurance_details');
        Schema::dropIfExists('job_card_additional_jobs');
        Schema::dropIfExists('job_card_issues');
        Schema::dropIfExists('job_card_observations');
        Schema::dropIfExists('job_card_media');
    }
};
