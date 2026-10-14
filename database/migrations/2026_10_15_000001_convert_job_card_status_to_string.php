<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move job_cards.status from a 7-value enum to a string validated by
 * App\Modules\Workshop\Enums\JobCardStatus, and remap existing data to the
 * life-cycle workflow statuses.
 */
return new class extends Migration
{
    private const MAP = [
        'draft' => 'intake_pending',
        'received' => 'intake_in_progress',
        'in_progress' => 'work_in_progress',
        'qc' => 'post_work_trial',
        'ready' => 'ready_for_delivery',
    ];

    public function up(): void
    {
        DB::statement("ALTER TABLE job_cards MODIFY status VARCHAR(40) NOT NULL DEFAULT 'intake_pending'");

        Schema::table('job_cards', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->string('cancel_reason', 500)->nullable()->after('status_changed_at');
        });

        Schema::table('job_card_status_history', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('notes');
        });

        DB::transaction(function () {
            foreach (self::MAP as $old => $new) {
                DB::table('job_cards')->where('status', $old)->update(['status' => $new]);
                DB::table('job_card_status_history')->where('from_status', $old)->update(['from_status' => $new]);
                DB::table('job_card_status_history')->where('to_status', $old)->update(['to_status' => $new]);
            }

            DB::table('job_cards')->whereNull('status_changed_at')->update([
                'status_changed_at' => DB::raw('updated_at'),
            ]);
        });
    }

    public function down(): void
    {
        // Lossy: statuses that only exist in the new workflow collapse to their nearest old status
        $reverse = array_flip(self::MAP) + [
            'pickup_scheduled' => 'draft',
            'pickup_en_route' => 'draft',
            'vehicle_collected' => 'draft',
            'awaiting_approval' => 'in_progress',
            'awaiting_parts' => 'in_progress',
            'wash_pending' => 'qc',
            'invoiced' => 'ready',
            'gate_pass_generated' => 'ready',
        ];

        DB::transaction(function () use ($reverse) {
            foreach ($reverse as $new => $old) {
                DB::table('job_cards')->where('status', $new)->update(['status' => $old]);
                DB::table('job_card_status_history')->where('from_status', $new)->update(['from_status' => $old]);
                DB::table('job_card_status_history')->where('to_status', $new)->update(['to_status' => $old]);
            }
        });

        Schema::table('job_card_status_history', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn(['status_changed_at', 'cancel_reason']);
        });

        DB::statement("ALTER TABLE job_cards MODIFY status ENUM('draft','received','in_progress','qc','ready','delivered','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
