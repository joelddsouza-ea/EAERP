<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing hand-off (owner feedback 2026-10-24):
 *  - the advisor sends a checked job to back office for the bill (billing_requested_*);
 *  - bill lines keep their cost so back office can recheck cost and selling price on the proforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->timestamp('billing_requested_at')->nullable();
            $table->foreignId('billing_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Internal: what the line cost the workshop (per unit); never printed
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_requested_by');
            $table->dropColumn('billing_requested_at');
        });
    }
};
