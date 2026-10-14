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
        // Job Cards (Branch-specific operational data)
        Schema::create('job_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('job_number', 30)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            $table->foreignId('oil_type_id')->nullable()->constrained('oil_types')->nullOnDelete();
            $table->foreignId('bay_id')->nullable()->constrained('bays')->nullOnDelete();
            $table->foreignId('service_advisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();

            // Status workflow: draft -> received -> in_progress -> qc -> ready -> delivered
            $table->enum('status', [
                'draft',
                'received',
                'in_progress',
                'qc',
                'ready',
                'delivered',
                'cancelled'
            ])->default('draft');

            // Service type
            $table->enum('service_type', [
                'scheduled',      // Regular service package
                'general_repair', // Ad-hoc mechanical repair
                'ac_service',     // AC specific
                'body_shop',      // Body shop work
                'electrical',     // Electrical work
                'detailing',      // Detailing only
                'home_service'    // Home service visit
            ])->default('scheduled');

            // Vehicle state at check-in
            $table->integer('km_reading')->nullable();
            $table->enum('fuel_level', ['empty', 'quarter', 'half', 'three_quarter', 'full'])->nullable();
            $table->json('inventory_items')->nullable(); // Items in vehicle (documents, tools, etc.)

            // Customer reported issues
            $table->text('customer_complaints')->nullable();

            // Advisor observations
            $table->text('advisor_observations')->nullable();

            // Estimated and actual times
            $table->timestamp('estimated_delivery')->nullable();
            $table->timestamp('time_in')->nullable();
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_completed_at')->nullable();
            $table->timestamp('qc_completed_at')->nullable();
            $table->timestamp('time_out')->nullable();

            // Pricing
            $table->decimal('estimated_amount', 12, 2)->default(0);
            $table->decimal('package_price', 10, 2)->default(0);
            $table->decimal('addons_total', 10, 2)->default(0);
            $table->decimal('labor_total', 10, 2)->default(0);
            $table->decimal('parts_total', 10, 2)->default(0);
            $table->decimal('third_party_total', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->string('discount_reason')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);

            // Notes
            $table->text('internal_notes')->nullable();

            // Approval
            $table->boolean('customer_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_signature')->nullable();

            // Priority
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'created_at']);
            $table->index(['technician_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['vehicle_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_cards');
    }
};
