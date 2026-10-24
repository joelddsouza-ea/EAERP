<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional parts & labour can be priced including or excluding GST (owner, 2026-10-24).
 * An excluded line's customer amount has its GST added on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->boolean('gst_inclusive')->default(true)->after('rate');
            $table->decimal('tax_rate', 5, 2)->default(18)->after('gst_inclusive');
        });
    }

    public function down(): void
    {
        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->dropColumn(['gst_inclusive', 'tax_rate']);
        });
    }
};
