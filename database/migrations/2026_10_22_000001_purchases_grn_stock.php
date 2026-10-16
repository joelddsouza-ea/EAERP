<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — purchases, GRN & stock (an optional module, switched on in the masters):
 *  - goods receipts (GRN): what the spare parts coordinator bought, from whom, on what bill,
 *    cash or credit; lines are a job's part, consumables into stock, or a third-party service;
 *  - the technician verifies a received part is the right one;
 *  - consumables stock per branch (weighted-average cost) issued to jobs, plan kits per plan × car class;
 *  - payments to suppliers against credit purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // oil | filter | consumable | detailing
            $table->string('category', 20)->default('consumable');
            $table->string('unit', 10)->default('nos');
            // Engine oil: the oil grade this stock item is (plan kits take the job's grade)
            $table->foreignId('oil_type_id')->nullable()->constrained('oil_types')->nullOnDelete();
            $table->string('hsn_code', 20)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->decimal('reorder_level', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            // Weighted-average cost per unit, GST-inclusive landed cost
            $table->decimal('avg_cost', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'stock_item_id']);
        });

        Schema::create('plan_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('service_packages')->cascadeOnDelete();
            // Null: every car class
            $table->foreignId('category_id')->nullable()->constrained('vehicle_categories')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->timestamps();

            $table->unique(['package_id', 'category_id', 'stock_item_id'], 'plan_kit_unique');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('grn_number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('supplier_bill_no', 60)->nullable();
            $table->date('bill_date');
            // gst_invoice | challan | no_bill
            $table->string('bill_type', 15);
            // cash | credit
            $table->string('payment_mode', 10);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('gst_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            // posted | cancelled
            $table->string('status', 12)->default('posted');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'bill_date']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            // job_part | stock | third_party
            $table->string('kind', 12);
            $table->foreignId('spare_part_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            // Third-party service: the estimate line it is for
            $table->string('estimate_line_key', 40)->nullable();
            $table->string('description');
            $table->string('part_number', 100)->nullable();
            $table->string('brand', 100)->nullable();
            $table->decimal('quantity', 10, 2);
            $table->string('unit', 10)->default('nos');
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('unit_cost', 12, 2);
            // incl | excl | cash (as on the supplier's quote)
            $table->string('gst_mode', 5);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('gst_amount', 12, 2);
            $table->decimal('total', 12, 2);
            // Job parts: pending | verified | wrong_part (by the technician)
            $table->string('verification', 12)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_note', 500)->nullable();
            // Sent back to the supplier (wrong part, job cancelled)
            $table->decimal('returned_quantity', 10, 2)->default(0);
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason', 500)->nullable();
            $table->timestamps();

            $table->index('job_card_id');
            $table->index('spare_part_request_id');
        });

        Schema::create('goods_receipt_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            // Null: the supplier's bill; set: a photo of that part
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            // in | issue | return | adjust | grn_cancel
            $table->string('type', 12);
            // + into stock, − out of stock
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 4);
            $table->decimal('value', 12, 2);
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'stock_item_id']);
            $table->index('job_card_id');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);
            $table->string('reference_number', 100)->nullable();
            $table->date('paid_on');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('supplier_id');
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->timestamp('kit_issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn('kit_issued_at');
        });
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('goods_receipt_media');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('plan_kit_items');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_items');
    }
};
