<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parts pricing loop: the advisor asks the spare parts coordinator for a price,
 * the coordinator records supplier quotes and picks one, the advisor adds a
 * margin on the estimate. Suppliers are reused by the Phase 3 GRN.
 *
 * spare_part_requests.status becomes a string:
 * requested → quoted → approved | declined → ordered → received (+ cancelled, issued, returned).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('requested')->change();
        });
        DB::table('spare_part_requests')->where('status', 'rejected')->update(['status' => 'declined']);

        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->uuid('estimate_line_key')->nullable()->after('job_card_id');
            $table->text('requested_note')->nullable()->after('remarks');
            $table->foreignId('quoted_by')->nullable()->after('requested_at')->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at')->nullable()->after('quoted_by');
            $table->foreignId('ordered_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('ordered_at')->nullable()->after('ordered_by');

            $table->index('estimate_line_key');
        });

        Schema::create('spare_part_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spare_part_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_cost', 10, 2);
            $table->string('gst_mode', 10); // incl | excl | cash
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->decimal('landed_unit_cost', 10, 2); // GST-inclusive cost per unit
            $table->string('availability')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->foreignId('selected_quote_id')->nullable()->after('quoted_at')
                ->constrained('spare_part_quotes')->nullOnDelete();
        });

        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->foreignId('spare_part_request_id')->nullable()->after('labour_service_id')
                ->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 10, 2)->nullable()->after('rate');
            $table->decimal('margin_pct', 6, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spare_part_request_id');
            $table->dropColumn(['unit_cost', 'margin_pct']);
        });
        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('selected_quote_id');
        });
        Schema::dropIfExists('spare_part_quotes');
        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->dropIndex(['estimate_line_key']);
            $table->dropConstrainedForeignId('quoted_by');
            $table->dropConstrainedForeignId('ordered_by');
            $table->dropColumn(['estimate_line_key', 'requested_note', 'quoted_at', 'ordered_at']);
        });
        DB::table('spare_part_requests')->whereNotIn('status', ['requested', 'approved', 'ordered', 'issued', 'received', 'returned'])
            ->update(['status' => 'requested']);
        Schema::table('spare_part_requests', function (Blueprint $table) {
            $table->enum('status', ['requested', 'approved', 'rejected', 'ordered', 'issued', 'received', 'returned'])
                ->default('requested')->change();
        });
        Schema::dropIfExists('suppliers');
    }
};
