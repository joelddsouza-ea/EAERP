<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fleet becomes a customer type next to Individual and Corporate (picked at intake).
 * customer_type 'fleet' and is_fleet are kept in step by the Customer model.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customers MODIFY customer_type ENUM('individual','corporate','fleet') NOT NULL DEFAULT 'individual'");
        DB::table('customers')->where('is_fleet', true)->update(['customer_type' => 'fleet']);
    }

    public function down(): void
    {
        DB::table('customers')->where('customer_type', 'fleet')->update(['customer_type' => 'corporate']);
        DB::statement("ALTER TABLE customers MODIFY customer_type ENUM('individual','corporate') NOT NULL DEFAULT 'individual'");
    }
};
