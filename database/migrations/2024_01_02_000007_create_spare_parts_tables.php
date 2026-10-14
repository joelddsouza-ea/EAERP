<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Spare Part Requests (linked to job cards)
        Schema::create('spare_part_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->string('part_name');
            $table->string('part_number')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('unit')->default('nos');
            $table->enum('status', [
                'requested',
                'approved',
                'rejected',
                'ordered',
                'issued',
                'received',
                'returned'
            ])->default('requested');
            $table->text('remarks')->nullable();
            $table->text('rejection_reason')->nullable();

            // Request tracking
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();

            // Approval tracking
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Issue tracking
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->decimal('issued_quantity', 8, 2)->nullable();

            // Receive tracking
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->decimal('received_quantity', 8, 2)->nullable();

            $table->timestamps();

            $table->index(['job_card_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spare_part_requests');
    }
};
