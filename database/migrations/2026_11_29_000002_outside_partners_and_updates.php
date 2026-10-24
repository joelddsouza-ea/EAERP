<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner feedback 2026-10-24:
 *  - washing and wheel alignment & balancing are done by outside partners, who get a login to
 *    upload photos/videos; several partners per kind, the advisor / technician picks one;
 *  - job-sheet sections can be "outside" sections (washing), sent to a partner from the sheet;
 *  - the customer's progress timeline can be switched off per job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_sections', function (Blueprint $table) {
            // null | washing | wheel_alignment: the section is done by an outside partner
            $table->string('outside_kind', 20)->nullable()->after('sort_order');
        });

        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->string('outside_kind', 20)->nullable()->after('section_name');
        });

        Schema::create('service_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            // washing | wheel_alignment
            $table->string('kind', 20);
            // null: works for every branch
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // Their login (role service-partner)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'is_active']);
        });

        Schema::create('job_outside_works', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('service_partner_id')->constrained()->restrictOnDelete();
            // sent → done (by the partner) → returned (car back); or cancelled
            $table->string('status', 12)->default('sent');
            $table->string('note', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->string('partner_note', 500)->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['service_partner_id', 'status']);
            $table->index(['job_card_id', 'status']);
        });

        Schema::table('job_cards', function (Blueprint $table) {
            // Off: the customer's page and app show no "Progress" list for this job
            $table->boolean('show_customer_timeline')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn('show_customer_timeline');
        });
        Schema::dropIfExists('job_outside_works');
        Schema::dropIfExists('service_partners');
        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->dropColumn('outside_kind');
        });
        Schema::table('checklist_sections', function (Blueprint $table) {
            $table->dropColumn('outside_kind');
        });
    }
};
