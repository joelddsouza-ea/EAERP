<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases & stock (owner, 2026-10-24):
 *  - the plan kit is issued from stock by itself at final QC; what was short is kept on the job;
 *  - an additional part can come from stock: a "quote" with the stock item and no supplier,
 *    priced at the average cost; it is issued to the job when the customer approves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            // [{stock_item_id, name, unit, quantity}] the automatic plan kit could not issue
            $table->json('kit_shortfall')->nullable()->after('kit_issued_at');
        });

        Schema::table('spare_part_quotes', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->change();
            $table->foreignId('stock_item_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spare_part_quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_id');
        });
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn('kit_shortfall');
        });
    }
};
