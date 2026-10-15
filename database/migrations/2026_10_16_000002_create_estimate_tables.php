<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned customer estimates. Plan lines are "included"; additional lines are
 * approved or declined one by one by the customer (or by an advisor on their
 * behalf, which is always logged in job_card_estimate_decisions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_card_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 20)->default('draft'); // draft | sent | superseded

            // Plan snapshot (masters may change later)
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('vehicle_categories')->nullOnDelete();
            $table->foreignId('oil_type_id')->nullable()->constrained('oil_types')->nullOnDelete();
            $table->string('package_name')->nullable();
            $table->string('category_name')->nullable();
            $table->string('oil_type_name')->nullable();
            $table->decimal('package_price', 10, 2)->default(0);

            $table->decimal('plan_discount_pct', 5, 2)->default(0);
            $table->decimal('plan_discount_flat', 10, 2)->default(0);
            $table->decimal('extras_discount_pct', 5, 2)->default(0);
            $table->decimal('extras_discount_flat', 10, 2)->default(0);

            $table->decimal('plan_total', 12, 2)->default(0);
            $table->decimal('extras_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);

            $table->unsignedInteger('next_service_km')->nullable();
            $table->date('next_service_date')->nullable();
            $table->string('payment_mode', 30)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('customer_viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['job_card_id', 'version']);
        });

        Schema::create('job_card_estimate_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('job_card_estimates')->cascadeOnDelete();
            $table->uuid('line_key'); // stable across versions
            $table->string('section', 20); // plan | additional
            $table->string('kind', 20); // oil | plan_item | plan_extra | labour_balance | part | labor | third_party
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);

            $table->string('approval_status', 20)->default('included'); // included | pending | approved | declined
            $table->string('decided_via', 30)->nullable(); // customer_link | advisor_on_behalf
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->foreignId('job_card_additional_job_id')->nullable()->constrained('job_card_additional_jobs')->nullOnDelete();
            $table->foreignId('job_card_issue_id')->nullable()->constrained('job_card_issues')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estimate_id', 'line_key']);
        });

        Schema::create('job_card_estimate_line_media', function (Blueprint $table) {
            $table->foreignId('estimate_line_id')->constrained('job_card_estimate_lines')->cascadeOnDelete();
            $table->foreignId('job_card_media_id')->constrained('job_card_media')->cascadeOnDelete();
            $table->primary(['estimate_line_id', 'job_card_media_id'], 'estimate_line_media_pk');
        });

        Schema::create('job_card_estimate_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('job_card_estimates')->cascadeOnDelete();
            $table->string('author_type', 20); // customer | staff
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // Append-only audit of every approval decision
        Schema::create('job_card_estimate_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_id')->constrained('job_card_estimates')->cascadeOnDelete();
            $table->uuid('line_key');
            $table->string('description');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('decided_via', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('customer_message_id')->nullable(); // FK added with customer_messages
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['job_card_id', 'created_at']);
        });

        // Billing line created from an approved estimate line (keyed by line_key, which survives re-versions)
        Schema::table('job_card_items', function (Blueprint $table) {
            $table->uuid('estimate_line_key')->nullable()->after('job_card_id');
            $table->index(['job_card_id', 'estimate_line_key']);
        });
    }

    public function down(): void
    {
        Schema::table('job_card_items', function (Blueprint $table) {
            $table->dropIndex(['job_card_id', 'estimate_line_key']);
            $table->dropColumn('estimate_line_key');
        });

        Schema::dropIfExists('job_card_estimate_decisions');
        Schema::dropIfExists('job_card_estimate_comments');
        Schema::dropIfExists('job_card_estimate_line_media');
        Schema::dropIfExists('job_card_estimate_lines');
        Schema::dropIfExists('job_card_estimates');
    }
};
