<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Activities flagged ⚠ on a technician job sheet become Additional Issues lines
 * (source "activity"), so they follow the same evidence → parts → estimate →
 * fitted-part → done process. Existing flagged-activity observations are moved over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_card_issues', function (Blueprint $table) {
            $table->foreignId('source_item_id')->nullable()->unique()->after('source')
                ->constrained('job_card_checklist_items')->nullOnDelete();
        });

        $observations = DB::table('job_card_observations')->where('source', 'activity')->whereNotNull('source_item_id')->get();
        foreach ($observations as $o) {
            DB::table('job_card_issues')->insert([
                'job_card_id' => $o->job_card_id,
                'source' => 'activity',
                'source_item_id' => $o->source_item_id,
                'description' => $o->text,
                'priority' => 'normal',
                'status' => 'open',
                'reported_by' => $o->created_by,
                'sort_order' => (int) DB::table('job_card_issues')->where('job_card_id', $o->job_card_id)->max('sort_order') + 1,
                'created_at' => $o->created_at,
                'updated_at' => now(),
            ]);
            DB::table('job_card_observations')->where('id', $o->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('job_card_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_item_id');
        });
    }
};
