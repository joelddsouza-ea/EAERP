<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in intake: the advisor's trial drive (done, or skipped with a reason).
 * Intake inventory photos/videos use job_card_media with attachable type "job_card".
 * Also the standard Laravel table for in-app staff notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->string('intake_trial_status', 10)->nullable(); // done | skipped
            $table->text('intake_trial_notes')->nullable();
            $table->foreignId('intake_trial_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('intake_trial_at')->nullable();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('intake_trial_by');
            $table->dropColumn(['intake_trial_status', 'intake_trial_notes', 'intake_trial_at']);
        });
    }
};
