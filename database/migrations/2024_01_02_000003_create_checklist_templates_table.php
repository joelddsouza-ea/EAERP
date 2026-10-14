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
        // Checklist Templates (per package)
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['pre_service', 'during_service', 'post_service', 'qc'])->default('during_service');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['package_id', 'type']);
        });

        // Checklist Sections (categories within a template)
        Schema::create('checklist_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Checklist Items
        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('checklist_sections')->cascadeOnDelete();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->enum('input_type', ['checkbox', 'text', 'number', 'select'])->default('checkbox');
            $table->json('options')->nullable(); // For select type
            $table->boolean('is_required')->default(false);
            $table->boolean('requires_photo')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_sections');
        Schema::dropIfExists('checklist_templates');
    }
};
