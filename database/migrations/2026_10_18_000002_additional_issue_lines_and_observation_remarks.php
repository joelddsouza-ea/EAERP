<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional Issues: one line per problem (customer complaints from intake and the
 * technician's own findings), each with photos/videos and the parts it needs.
 * Observations get a remark from a master list (Customer not approved, Fix at next
 * service, Fix within _ days, ...) and are also raised from declined estimate lines
 * and deferred issue lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_card_issues', function (Blueprint $table) {
            $table->string('source', 15)->default('customer')->after('job_card_id'); // customer | technician
        });

        Schema::table('job_card_media', function (Blueprint $table) {
            $table->string('tag', 20)->nullable()->after('media_type'); // fitted_part
        });

        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->foreignId('job_card_issue_id')->nullable()->after('job_card_additional_job_id')
                ->constrained('job_card_issues')->nullOnDelete();
        });

        Schema::create('observation_remarks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label');
            $table->boolean('needs_days')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();
        });

        Schema::table('job_card_observations', function (Blueprint $table) {
            $table->foreignId('remark_id')->nullable()->after('severity')->constrained('observation_remarks')->nullOnDelete();
            $table->unsignedSmallInteger('remark_days')->nullable()->after('remark_id');
            $table->uuid('estimate_line_key')->nullable()->after('source_item_id');
            $table->foreignId('job_card_issue_id')->nullable()->after('estimate_line_key')
                ->constrained('job_card_issues')->cascadeOnDelete();

            $table->index(['job_card_id', 'estimate_line_key']);
        });
    }

    public function down(): void
    {
        Schema::table('job_card_observations', function (Blueprint $table) {
            $table->dropIndex(['job_card_id', 'estimate_line_key']);
            $table->dropConstrainedForeignId('job_card_issue_id');
            $table->dropConstrainedForeignId('remark_id');
            $table->dropColumn(['remark_days', 'estimate_line_key']);
        });
        Schema::dropIfExists('observation_remarks');
        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_card_issue_id');
        });
        Schema::table('job_card_media', function (Blueprint $table) {
            $table->dropColumn('tag');
        });
        Schema::table('job_card_issues', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
