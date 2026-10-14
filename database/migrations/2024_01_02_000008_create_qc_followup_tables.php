<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // QC Sign-offs
        Schema::create('qc_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->enum('phase', ['pre_delivery', 'final']);
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('passed')->default(false);
            $table->text('remarks')->nullable();
            $table->json('failed_items')->nullable(); // List of items that failed
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();

            $table->index(['job_card_id', 'phase']);
        });

        // Follow-up Calls
        Schema::create('follow_up_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('call_type', [
                'courtesy_2day',    // 2-day courtesy call
                'feedback',         // Service feedback
                'next_service',     // Next service reminder
                'complaint',        // Complaint follow-up
                'other'
            ])->default('courtesy_2day');
            $table->enum('status', ['scheduled', 'completed', 'no_answer', 'rescheduled', 'cancelled'])->default('scheduled');
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('call_duration')->nullable(); // in seconds
            $table->integer('rating')->nullable(); // 1-5 rating
            $table->text('feedback')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['scheduled_date', 'status']);
            $table->index(['job_card_id', 'call_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_up_calls');
        Schema::dropIfExists('qc_signoffs');
    }
};
