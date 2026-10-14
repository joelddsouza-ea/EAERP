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
        // Job Card Items (labor, parts, services)
        Schema::create('job_card_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['labor', 'part', 'service', 'third_party'])->default('labor');
            $table->string('description');
            $table->string('part_number')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('unit')->default('nos');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['job_card_id', 'type']);
        });

        // Job Card Addons (wheel alignment, etc.)
        Schema::create('job_card_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained('service_addons')->nullOnDelete();
            $table->string('addon_name');
            $table->decimal('price', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('final_price', 10, 2);
            $table->timestamps();
        });

        // Job Card Checklist (completed checklist for this job)
        Schema::create('job_card_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['job_card_id', 'status']);
        });

        // Job Card Checklist Items (individual item completion)
        Schema::create('job_card_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_checklist_id')->constrained('job_card_checklists')->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('checklist_items')->cascadeOnDelete();
            $table->boolean('is_completed')->default(false);
            $table->string('value')->nullable(); // For text/number inputs
            $table->text('remarks')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['job_card_checklist_id', 'is_completed'], 'jc_checklist_items_completed_idx');
        });

        // Job Card Photos
        Schema::create('job_card_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->enum('photo_type', [
                'before',        // Before service
                'after',         // After service
                'parts',         // Parts requiring replacement
                'damage',        // Damage documentation
                'customer_items', // Customer belongings
                'inspection',    // Inspection photos
                'other'
            ])->default('other');
            $table->string('file_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('caption')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_card_id', 'photo_type']);
        });

        // Job Card Status History
        Schema::create('job_card_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_card_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_card_status_history');
        Schema::dropIfExists('job_card_photos');
        Schema::dropIfExists('job_card_checklist_items');
        Schema::dropIfExists('job_card_checklists');
        Schema::dropIfExists('job_card_addons');
        Schema::dropIfExists('job_card_items');
    }
};
