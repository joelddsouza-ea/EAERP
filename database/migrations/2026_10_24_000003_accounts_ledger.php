<?php

use App\Modules\Accounts\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — basic double-entry accounting:
 *  - chart of accounts (seeded; owner can add heads), bank accounts;
 *  - journal entries with balanced lines, each tied to what caused it (a bill, a receipt,
 *    a GRN, a payslip…) so it can be reposted or reversed; party-wise lines for customers,
 *    suppliers and employees;
 *  - expenses (cash, bank, a supplier on credit, or customer money an employee holds);
 *  - month locks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 120);
            // asset | liability | equity | income | expense
            $table->string('type', 10);
            // System role of the head (cash, bank, receivable, payable, gst_output_cgst…), null for plain heads
            $table->string('sub_type', 30)->nullable();
            // Balance-sheet / P&L group shown in reports
            $table->string('group', 40)->nullable();
            $table->boolean('is_system')->default(false);
            // Offered on the expense form
            $table->boolean('is_expense_head')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('sub_type');
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('account_number', 30)->nullable();
            $table->string('ifsc', 15)->nullable();
            // Card / UPI / transfers land here when no bank is chosen
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 24)->unique();
            $table->date('entry_date');
            // sale | receipt | refund | purchase | supplier_payment | stock | expense | payroll | salary_payment | advance | custody | contra | journal | opening
            $table->string('voucher_type', 20);
            $table->nullableMorphs('source');
            $table->string('narration', 500)->nullable();
            // posted | reversed (a reversed entry has a reversal entry pointing at it)
            $table->string('status', 10)->default('posted');
            $table->foreignId('reversal_of')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entry_date', 'branch_id']);
            $table->index(['voucher_type', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // customer | supplier | user
            $table->string('party_type', 10)->nullable();
            $table->unsignedBigInteger('party_id')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index(['party_type', 'party_id']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('number', 24)->unique();
            $table->date('expense_date');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('paid_to', 150);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // Total paid, GST included
            $table->decimal('amount', 12, 2);
            $table->boolean('gst_bill')->default(false);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->string('supplier_gstin', 20)->nullable();
            $table->string('bill_number', 60)->nullable();
            // cash | bank | staff_custody | credit (owed to the supplier)
            $table->string('paid_via', 15);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            // staff_custody: whose collected money paid it
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('bill_disk', 30)->nullable();
            $table->string('bill_path')->nullable();
            // posted | cancelled
            $table->string('status', 10)->default('posted');
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'expense_date']);
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        ChartOfAccounts::seed();
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('accounts');
    }
};
