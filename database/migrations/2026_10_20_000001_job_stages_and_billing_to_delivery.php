<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Job stages master: which statuses the system moves into by itself, what each
 *   stage waits for, and the WhatsApp message the customer gets on entering it.
 * - Billing to delivery: proforma (final estimate) → tax invoice or close on
 *   proforma; payments taken against the job at any time (advance, during work,
 *   final); a gate pass only once paid (or a manager override); no gate pass, no delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->string('status', 40)->unique();
            $table->string('label', 80);
            $table->string('waiting_for', 255)->nullable();
            $table->boolean('auto')->default(true);
            // off | first_entry | always
            $table->string('notify_customer', 20)->default('off');
            $table->text('message')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach (self::stages() as $i => $stage) {
            DB::table('workflow_stages')->insert([...$stage, 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        // ---------------------------------------------------------------- billing
        DB::statement("ALTER TABLE invoices MODIFY status VARCHAR(20) NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE invoice_items MODIFY type VARCHAR(30) NOT NULL DEFAULT 'service'");

        Schema::table('invoices', function (Blueprint $table) {
            // proforma = the final estimate the customer pays against; tax = the GST invoice
            $table->string('kind', 10)->default('tax')->after('invoice_number');
            $table->foreignId('proforma_id')->nullable()->after('kind')->constrained('invoices')->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->after('proforma_id')->constrained('job_card_estimates')->nullOnDelete();
            $table->string('customer_gstin', 20)->nullable()->after('place_of_supply');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_note', 500)->nullable();

            $table->index(['job_card_id', 'kind', 'status']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // Inclusive: unit price already contains GST, which is extracted. Otherwise GST is added on top.
            $table->boolean('gst_inclusive')->default(false)->after('tax_rate');
            $table->boolean('gst_applicable')->default(true)->after('gst_inclusive');
            $table->string('estimate_line_key', 40)->nullable()->after('invoice_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->change();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('job_card_id')->constrained()->nullOnDelete();
            // advance (before the final bill) | part | final
            $table->string('stage', 10)->nullable()->after('amount');

            $table->index(['job_card_id', 'status']);
        });

        // Existing payments belong to their invoice's job
        DB::statement('UPDATE payments p JOIN invoices i ON i.id = p.invoice_id SET p.job_card_id = i.job_card_id, p.customer_id = i.customer_id');

        Schema::create('gate_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->decimal('amount_due', 12, 2)->default(0);
            // Issued before full payment by an owner/manager
            $table->boolean('override')->default(false);
            $table->string('override_reason', 500)->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('vehicle_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vehicle_out_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['job_card_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_passes');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['job_card_id', 'status']);
            $table->dropConstrainedForeignId('job_card_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('stage');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['gst_inclusive', 'gst_applicable', 'estimate_line_key', 'sort_order']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['job_card_id', 'kind', 'status']);
            $table->dropConstrainedForeignId('proforma_id');
            $table->dropConstrainedForeignId('estimate_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['kind', 'customer_gstin', 'approved_at', 'closed_at', 'close_note']);
        });

        Schema::dropIfExists('workflow_stages');
    }

    /**
     * Defaults; the owner edits them in Job Sheet Masters › Job stages.
     */
    public static function stages(): array
    {
        return [
            [
                'status' => 'intake_pending',
                'label' => 'Intake Pending',
                'waiting_for' => 'The advisor starts intake: trial drive or the first inventory photo/video.',
                'auto' => false,
                'notify_customer' => 'off',
                'message' => null,
            ],
            [
                'status' => 'intake_in_progress',
                'label' => 'Intake In Progress',
                'waiting_for' => 'Intake complete (odometer, fuel, trial drive, inventory photos/videos) and a technician assigned.',
                'auto' => true,
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, your car :car has been received at :workshop (Job :job).\nFollow its progress, photos and estimate here: :link",
            ],
            [
                'status' => 'awaiting_approval',
                'label' => 'Awaiting Approval',
                'waiting_for' => 'The customer approves or declines every additional item on the estimate.',
                'auto' => true,
                // The estimate itself goes with its own message
                'notify_customer' => 'off',
                'message' => "Hello :name, some additional work on your car :car (Job :job) needs your approval.\nPlease approve or decline here: :link",
            ],
            [
                'status' => 'work_in_progress',
                'label' => 'Work In Progress',
                'waiting_for' => 'Every technician job sheet is submitted.',
                'auto' => true,
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, work has started on your car :car (Job :job).\nTrack it here: :link",
            ],
            [
                'status' => 'awaiting_parts',
                'label' => 'Awaiting Parts',
                'waiting_for' => 'All approved parts are received on the Parts Desk.',
                'auto' => true,
                'notify_customer' => 'off',
                'message' => "Hello :name, we are waiting for parts for your car :car (Job :job). We will update you as soon as they arrive.\n:link",
            ],
            [
                'status' => 'post_work_trial',
                'label' => 'Trial Drive',
                'waiting_for' => 'The advisor records the post-service trial drive: OK, or issues found (back to work).',
                'auto' => true,
                'notify_customer' => 'off',
                'message' => null,
            ],
            [
                'status' => 'wash_pending',
                'label' => 'Wash Pending',
                'waiting_for' => 'Washing done is recorded.',
                'auto' => true,
                'notify_customer' => 'off',
                'message' => null,
            ],
            [
                'status' => 'ready_for_delivery',
                'label' => 'Ready For Delivery',
                'waiting_for' => 'Back office approves the final bill (made after the final QC).',
                'auto' => true,
                'notify_customer' => 'always',
                'message' => "Hello :name, your car :car is ready for delivery (Job :job).\nDetails: :link",
            ],
            [
                'status' => 'invoiced',
                'label' => 'Invoiced',
                'waiting_for' => 'Full payment received (an owner/manager can issue the gate pass earlier, with a reason).',
                'auto' => true,
                'notify_customer' => 'always',
                'message' => "Hello :name, the bill for your car :car (Job :job) is ready: Rs :amount.\nAmount to pay: Rs :balance\nView the bill here: :link",
            ],
            [
                'status' => 'gate_pass_generated',
                'label' => 'Gate Pass Generated',
                'waiting_for' => 'The car leaves the workshop: "Vehicle out" on the gate pass.',
                'auto' => true,
                'notify_customer' => 'always',
                'message' => "Hello :name, thank you for the payment. Gate pass :gate_pass is issued; your car :car (Job :job) is ready to collect.\n:link",
            ],
            [
                'status' => 'delivered',
                'label' => 'Delivered',
                'waiting_for' => null,
                'auto' => true,
                'notify_customer' => 'always',
                'message' => "Thank you for choosing :workshop, :name! We hope you are happy with the service on your car :car.\nYour job details remain available here: :link",
            ],
        ];
    }
};
