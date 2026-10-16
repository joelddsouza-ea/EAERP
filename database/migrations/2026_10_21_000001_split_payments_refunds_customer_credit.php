<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, every rupee accounted for:
 *  - a receipt can be paid several ways at once (split payment): payment_parts;
 *  - money paid beyond the bill is refunded (negative payment, stage "refund") or kept
 *    as the customer's credit (stage "credit", with a customer_credits entry), and the
 *    credit can pay a later job (method "customer_credit");
 *  - bills count every payment on the job again (no cap at the bill total), so an
 *    overpayment shows as "Refund due" until it is settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        // "split" and "customer_credit" join the payment methods
        DB::statement("ALTER TABLE payments MODIFY method VARCHAR(20) NOT NULL DEFAULT 'cash'");

        Schema::create('payment_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reference_number', 100)->nullable();
            $table->string('cheque_number', 50)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->timestamps();

            $table->index('method');
        });

        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            // + kept as credit, − used on a job
            $table->decimal('amount', 12, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('customer_id');
        });

        // Every existing payment gets its single part
        DB::statement("INSERT INTO payment_parts (payment_id, method, amount, reference_number, cheque_number, bank_name, created_at, updated_at)
            SELECT id, method, amount, reference_number, cheque_number, bank_name, created_at, updated_at FROM payments");

        // Bills count every completed payment on their job again; an overpayment shows as refund due
        DB::statement("UPDATE invoices i SET paid_amount = (
                SELECT COALESCE(SUM(p.amount), 0) FROM payments p
                WHERE p.job_card_id = i.job_card_id AND p.status = 'completed' AND p.deleted_at IS NULL
            ) WHERE i.job_card_id IS NOT NULL");
        DB::statement('UPDATE invoices SET balance_amount = total_amount - paid_amount WHERE job_card_id IS NOT NULL');
        DB::statement("UPDATE invoices SET status = CASE
                WHEN balance_amount <= 0 THEN 'paid' WHEN paid_amount > 0 THEN 'partial' ELSE 'issued' END
            WHERE job_card_id IS NOT NULL AND kind = 'tax' AND status IN ('issued', 'partial', 'paid')");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
        Schema::dropIfExists('payment_parts');
        DB::statement("UPDATE payments SET method = 'cash' WHERE method NOT IN ('cash', 'card', 'upi', 'bank_transfer', 'cheque', 'wallet')");
        DB::statement("ALTER TABLE payments MODIFY method ENUM('cash', 'card', 'upi', 'bank_transfer', 'cheque', 'wallet') NOT NULL DEFAULT 'cash'");
    }
};
