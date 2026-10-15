<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price masters as kept in the owner's estimate sheet: 7 car types, oil grade
 * product + rate per litre, engine-oil litres per plan and car type, and the
 * items shown inside each plan. customized_at marks rows edited in the app so
 * re-seeding never overwrites them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oil_types', function (Blueprint $table) {
            $table->string('product_name')->nullable()->after('name');
            $table->decimal('rate_per_litre', 10, 2)->default(0)->after('product_name');
            $table->timestamp('customized_at')->nullable();
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->unsignedInteger('next_service_km')->nullable()->after('send_customer_report');
            $table->unsignedSmallInteger('next_service_months')->nullable()->after('next_service_km');
            $table->timestamp('customized_at')->nullable();
        });

        Schema::table('package_pricing', function (Blueprint $table) {
            $table->timestamp('customized_at')->nullable();
        });

        Schema::create('package_oil_litres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('service_packages')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $table->decimal('litres', 5, 2);
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'category_id']);
        });

        Schema::create('package_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained('service_packages')->cascadeOnDelete();
            $table->string('description');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'description']);
        });

        Schema::create('package_line_item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_item_id')->constrained('package_line_items')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            // 0 = item not included in the plan for this car type
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('customized_at')->nullable();
            $table->timestamps();

            $table->unique(['line_item_id', 'category_id']);
        });

        // "Premium Hatchback" is merged into "Prem. Hatch / Sedan / Compact SUV"
        $old = DB::table('vehicle_categories')->where('slug', 'premium-hatchback')->value('id');
        $new = DB::table('vehicle_categories')->where('slug', 'sedan-compact-suv')->value('id');

        if ($old && $new) {
            DB::table('vehicles')->where('category_id', $old)->update(['category_id' => $new]);
            DB::table('vehicle_models')->where('default_category_id', $old)->update(['default_category_id' => $new]);
            DB::table('package_pricing')->where('category_id', $old)->delete();
            DB::table('vehicle_categories')->where('id', $old)->update(['is_active' => false]);
        }
    }

    public function down(): void
    {
        DB::table('vehicle_categories')->where('slug', 'premium-hatchback')->update(['is_active' => true]);

        Schema::dropIfExists('package_line_item_prices');
        Schema::dropIfExists('package_line_items');
        Schema::dropIfExists('package_oil_litres');

        Schema::table('package_pricing', fn (Blueprint $table) => $table->dropColumn('customized_at'));
        Schema::table('service_packages', fn (Blueprint $table) => $table->dropColumn(['next_service_km', 'next_service_months', 'customized_at']));
        Schema::table('oil_types', fn (Blueprint $table) => $table->dropColumn(['product_name', 'rate_per_litre', 'customized_at']));
    }
};
