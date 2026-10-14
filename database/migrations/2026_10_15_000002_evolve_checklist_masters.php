<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turn checklist templates into job-sheet masters: sheet kinds, plan/service-type
 * matching, and per-activity media / N/A rules editable by the owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->string('sheet_kind', 30)->default('service_activities')->after('type');
            $table->string('service_type', 30)->nullable()->after('sheet_kind');
            $table->boolean('applies_to_all')->default(false)->after('service_type');
            $table->string('completion_rule', 20)->default('all_resolved')->after('applies_to_all');
            $table->string('filled_by', 20)->default('technician')->after('completion_rule');
            $table->boolean('has_issues_tab')->default(false)->after('filled_by');
            $table->boolean('has_observations')->default(false)->after('has_issues_tab');
            $table->unsignedSmallInteger('version')->default(1)->after('has_observations');
        });

        // One template can serve several plans (e.g. the Observation Report for PRO/360/ELITE)
        Schema::create('checklist_template_package', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('service_packages')->cascadeOnDelete();
            $table->unique(['template_id', 'package_id']);
        });

        DB::statement("ALTER TABLE checklist_items MODIFY input_type VARCHAR(20) NOT NULL DEFAULT 'tick'");

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->string('code', 80)->nullable()->unique()->after('section_id');
            $table->string('media_requirement', 16)->default('none')->after('requires_photo');
            $table->unsignedTinyInteger('min_media_count')->default(1)->after('media_requirement');
            $table->boolean('na_allowed')->default(false)->after('min_media_count');
            $table->string('expected_answer', 3)->nullable()->after('na_allowed');
            $table->json('applies_to_package_ids')->nullable()->after('expected_answer');
            $table->string('source_marker', 40)->nullable()->after('applies_to_package_ids');
            $table->timestamp('rules_customized_at')->nullable()->after('source_marker');
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->boolean('send_customer_report')->default(false)->after('home_service_available');
        });

        DB::transaction(function () {
            DB::table('checklist_items')->where('input_type', 'checkbox')->update(['input_type' => 'tick']);
            DB::table('checklist_items')->where('requires_photo', true)->update(['media_requirement' => 'photo']);

            foreach (DB::table('checklist_templates')->whereNotNull('package_id')->get(['id', 'package_id']) as $t) {
                DB::table('checklist_template_package')->insert(['template_id' => $t->id, 'package_id' => $t->package_id]);
            }

            // Preserve current behaviour: package-less templates applied to every job
            DB::table('checklist_templates')->whereNull('package_id')->update(['applies_to_all' => true]);
            DB::table('checklist_templates')->where('type', 'qc')->update([
                'sheet_kind' => 'pre_delivery',
                'filled_by' => 'advisor',
            ]);

            DB::table('service_packages')->whereIn('slug', ['ea-360', 'ea-elite'])->update(['send_customer_report' => true]);
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropColumn('send_customer_report');
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code', 'media_requirement', 'min_media_count', 'na_allowed', 'expected_answer',
                'applies_to_package_ids', 'source_marker', 'rules_customized_at',
            ]);
        });

        DB::table('checklist_items')->whereNotIn('input_type', ['text', 'number', 'select'])->update(['input_type' => 'checkbox']);
        DB::statement("ALTER TABLE checklist_items MODIFY input_type ENUM('checkbox','text','number','select') NOT NULL DEFAULT 'checkbox'");

        Schema::dropIfExists('checklist_template_package');

        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn([
                'sheet_kind', 'service_type', 'applies_to_all', 'completion_rule', 'filled_by',
                'has_issues_tab', 'has_observations', 'version',
            ]);
        });
    }
};
