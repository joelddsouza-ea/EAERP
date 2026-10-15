<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Several technicians on a job (one lead, kept in job_cards.technician_id) and a
 *   history of bay moves.
 * - Pre-delivery QC: 3 random checklist points need a photo/video shot within 10 s of
 *   being marked (the points are hidden until marked).
 * - Car classes edited in the app are kept when the seeders run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_card_technicians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['job_card_id', 'user_id']);
        });

        Schema::create('job_card_bay_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_bay_id')->nullable()->constrained('bays')->nullOnDelete();
            $table->foreignId('to_bay_id')->nullable()->constrained('bays')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at')->nullable();
            $table->timestamps();
        });

        // Existing lead technicians become the first row
        $now = now();
        foreach (DB::table('job_cards')->whereNotNull('technician_id')->get(['id', 'technician_id']) as $job) {
            DB::table('job_card_technicians')->insert([
                'job_card_id' => $job->id,
                'user_id' => $job->technician_id,
                'is_lead' => true,
                'assigned_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->boolean('qc_evidence_required')->default(false);
            $table->timestamp('qc_marked_at')->nullable();
        });

        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->timestamp('customized_at')->nullable();
        });
        Schema::table('vehicle_models', function (Blueprint $table) {
            $table->timestamp('customized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_models', fn (Blueprint $table) => $table->dropColumn('customized_at'));
        Schema::table('vehicle_categories', fn (Blueprint $table) => $table->dropColumn('customized_at'));
        Schema::table('job_card_checklist_items', function (Blueprint $table) {
            $table->dropColumn(['qc_evidence_required', 'qc_marked_at']);
        });
        Schema::dropIfExists('job_card_bay_moves');
        Schema::dropIfExists('job_card_technicians');
    }
};
