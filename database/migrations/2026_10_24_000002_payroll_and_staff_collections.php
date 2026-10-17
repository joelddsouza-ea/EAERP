<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — payroll:
 *  - incentive rules (automatic, from the month's bills) and manual adjustments
 *    (incentive, bonus, reimbursement, deduction, penalty);
 *  - salary advances, recovered month by month;
 *  - customer money an employee collected (own UPI / cash in hand): a custody ledger per
 *    employee, cleared by handing it over, spending it on the workshop, or salary recovery;
 *  - payroll runs with one payslip (and its lines) per employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incentive_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Role slugs the rule pays (technicians share a job's amount evenly)
            $table->json('roles');
            // per_job_delivered | pct_labour | pct_parts | pct_bill | per_plan_sold
            $table->string('basis', 20);
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            // ₹ per job / plan, or a percentage
            $table->decimal('value', 10, 2);
            // Paid only when the person's billed total for the month reaches this
            $table->decimal('min_monthly_billing', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('given_on');
            $table->string('method', 20)->default('cash');
            $table->string('reference_number', 100)->nullable();
            // 0 = recover the whole balance next payroll
            $table->decimal('recover_per_month', 12, 2)->default(0);
            $table->decimal('recovered', 12, 2)->default(0);
            // open | closed | cancelled
            $table->string('status', 10)->default('open');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            // incentive | bonus | reimbursement | deduction | penalty
            $table->string('kind', 15);
            $table->decimal('amount', 12, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['period', 'user_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            // null = every branch
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->char('period', 7);
            // draft | finalised | paid
            $table->string('status', 10)->default('draft');
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalised_at')->nullable();
            $table->timestamps();

            $table->index('period');
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('paid_days', 5, 1);
            $table->decimal('lop_days', 5, 1)->default(0);
            $table->decimal('present_days', 5, 1)->default(0);
            $table->decimal('leave_days', 5, 1)->default(0);
            $table->unsignedSmallInteger('off_day_works')->default(0);
            $table->unsignedInteger('ot_minutes')->default(0);
            $table->decimal('monthly_salary', 12, 2);
            $table->decimal('gross', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('employer_cost', 12, 2)->default(0);
            $table->decimal('net', 12, 2)->default(0);
            // A line edited by hand: recalculation keeps the edits
            $table->boolean('has_edits')->default(false);
            // unpaid | paid
            $table->string('pay_status', 10)->default('unpaid');
            $table->date('paid_on')->nullable();
            $table->string('pay_method', 20)->nullable();
            $table->string('pay_reference', 100)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            // salary | ot | offday | incentive | bonus | reimbursement | advance | custody | deduction | penalty | pt | pf | esi | pf_employer | esi_employer
            $table->string('code', 20);
            $table->string('label', 150);
            // earning | deduction | employer
            $table->string('kind', 10);
            $table->decimal('amount', 12, 2);
            $table->nullableMorphs('source');
            $table->string('edited_note', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('payment_parts', function (Blueprint $table) {
            // Method "staff_collected": the employee who holds the money
            $table->foreignId('collected_by')->nullable()->after('bank_name')->constrained('users')->nullOnDelete();
        });

        Schema::create('staff_custody_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('entry_date');
            // + collected from a customer, − handed over / spent / recovered from salary
            $table->decimal('amount', 12, 2);
            // collected | handed_over | spent | salary_recovery | adjustment
            $table->string('kind', 16);
            $table->foreignId('payment_part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            // How it was handed over: cash | bank
            $table->string('method', 20)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_custody_entries');
        Schema::table('payment_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
        });
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('salary_advances');
        Schema::dropIfExists('incentive_rules');
    }
};
