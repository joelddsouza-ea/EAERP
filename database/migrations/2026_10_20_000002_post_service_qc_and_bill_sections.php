<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Post-service order, as the owner runs it: trial drive → wash → final QC
 * (pre-delivery checklist) → bill & payment → gate pass. Each step is recorded,
 * so the job moves on by itself:
 * - the advisor records the post-service trial drive (OK / issues found) and "washing done";
 * - new stage Final QC (qc_pending) between Wash Pending and Ready For Delivery.
 * Bill lines remember their section (plan / additional / other) so the tax invoice
 * lists every service-plan line with its HSN/SAC under the plan's heading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->string('post_trial_result', 10)->nullable()->after('intake_trial_at'); // ok | issues
            $table->text('post_trial_notes')->nullable()->after('post_trial_result');
            $table->foreignId('post_trial_by')->nullable()->after('post_trial_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('post_trial_at')->nullable()->after('post_trial_by');
            $table->foreignId('wash_done_by')->nullable()->after('post_trial_at')->constrained('users')->nullOnDelete();
            $table->timestamp('wash_done_at')->nullable()->after('wash_done_by');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // plan | additional | other
            $table->string('section', 15)->nullable()->after('estimate_line_key');
        });

        $now = now();
        DB::table('workflow_stages')->updateOrInsert(['status' => 'qc_pending'], [
            'label' => 'Final QC',
            'waiting_for' => 'The advisor fills and submits the pre-delivery QC checklist (after the wash).',
            'auto' => true,
            'notify_customer' => 'off',
            'message' => null,
            'sort_order' => 75,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // New wording for the stages around it (kept if the owner already edited them)
        $texts = [
            'post_work_trial' => ['label' => 'Trial Drive', 'waiting_for' => 'The advisor records the post-service trial drive: OK, or issues found (back to work).'],
            'wash_pending' => ['label' => 'Wash Pending', 'waiting_for' => 'Washing done is recorded.', 'auto' => true],
            'ready_for_delivery' => ['label' => 'Ready For Delivery', 'waiting_for' => 'Back office approves the final bill (made after the final QC).'],
        ];
        foreach ($texts as $status => $values) {
            DB::table('workflow_stages')->where('status', $status)->whereNull('customized_at')->update([...$values, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('workflow_stages')->where('status', 'qc_pending')->delete();

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('post_trial_by');
            $table->dropConstrainedForeignId('wash_done_by');
            $table->dropColumn(['post_trial_result', 'post_trial_notes', 'post_trial_at', 'wash_done_at']);
        });
    }
};
