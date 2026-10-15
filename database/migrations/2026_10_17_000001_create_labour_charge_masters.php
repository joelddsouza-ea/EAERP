<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule of labour charges (owner's "Labor Charges" chart): GST-inclusive rate
 * per service × car type × fuel group, applied to additional jobs and general
 * repairs. A null rate means the service is not offered for that car (chart "-").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit', 10)->default('job'); // job | hour
            $table->decimal('gst_rate', 5, 2)->default(18);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name');
        });

        Schema::create('labour_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('labour_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $table->string('fuel_group', 10); // petrol | diesel
            $table->decimal('rate', 10, 2)->nullable();
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();

            $table->unique(['labour_service_id', 'category_id', 'fuel_group']);
        });

        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->foreignId('labour_service_id')->nullable()->after('kind')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labour_service_id');
        });
        Schema::dropIfExists('labour_rates');
        Schema::dropIfExists('labour_services');
    }
};
