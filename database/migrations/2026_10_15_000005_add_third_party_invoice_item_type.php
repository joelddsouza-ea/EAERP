<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Job card items of type third_party are copied to invoices as-is, but the invoice
 * item enum didn't allow it, so invoicing such a job failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE invoice_items MODIFY type ENUM('package','labor','part','service','addon','third_party','other') NOT NULL DEFAULT 'service'");
    }

    public function down(): void
    {
        DB::table('invoice_items')->where('type', 'third_party')->update(['type' => 'other']);
        DB::statement("ALTER TABLE invoice_items MODIFY type ENUM('package','labor','part','service','addon','other') NOT NULL DEFAULT 'service'");
    }
};
