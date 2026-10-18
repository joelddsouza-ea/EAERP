<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — the customer app:
 *  - sign-in: app sessions (own tokens, separate from staff logins) and one-time codes;
 *  - pay by UPI: the customer reports a UPI payment, back office confirms it;
 *  - inbox and web-push subscriptions; the customer's documents (insurance, PUC, RC);
 *  - AI assistant: every request with its tokens and cost, and estimate-line explanations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            // How they signed in: job_link | crm_link | otp
            $table->string('via', 20);
            $table->string('device', 250)->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 15);
            $table->string('code_hash', 64);
            // Kept encrypted so staff can read it out while codes aren't sent by WhatsApp
            $table->text('code_encrypted');
            // interakt | staff
            $table->string('delivered_via', 10);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            $table->index(['phone', 'created_at']);
        });

        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            // The UPI transaction reference (UTR) from the customer's UPI app
            $table->string('utr', 30)->unique();
            $table->string('screenshot_disk', 30)->nullable();
            $table->string('screenshot_path')->nullable();
            // pending | confirmed | rejected
            $table->string('status', 10)->default('pending');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'branch_id']);
        });

        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // stage | approval | payment | driver | service_due | insurance_due | puc_due | document_due | pending_work | booking | offer
            $table->string('kind', 20);
            $table->string('title', 150);
            $table->string('body', 500)->nullable();
            // Screen in the app, e.g. /app/jobs/12
            $table->string('link', 200)->nullable();
            // Same reminder not repeated (e.g. "service_due:vehicle:5:2026-11-02:7")
            $table->string('dedupe_key', 120)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('pushed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'read_at']);
            $table->unique(['customer_id', 'dedupe_key']);
        });

        Schema::create('customer_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 200);
            $table->string('auth', 100);
            $table->string('device', 250)->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            // insurance | puc | rc | licence | other
            $table->string('type', 15);
            $table->string('title', 120)->nullable();
            $table->string('number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('file_disk', 30);
            $table->string('file_path');
            $table->string('mime', 100)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'expiry_date']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('puc_expiry')->nullable()->after('insurance_expiry');
        });

        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            // diagnose | explain | accident | nudge
            $table->string('feature', 15);
            $table->string('model', 40);
            $table->text('question')->nullable();
            $table->unsignedTinyInteger('photos')->default(0);
            $table->json('answer')->nullable();
            $table->string('stop_reason', 20)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_inr', 10, 2)->default(0);
            $table->string('error', 255)->nullable();
            // What the customer did next: booked | lead | null
            $table->string('outcome', 10)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('ai_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained('job_card_estimates')->cascadeOnDelete();
            $table->string('line_key', 80);
            $table->text('text');
            $table->timestamps();

            $table->unique(['estimate_id', 'line_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_explanations');
        Schema::dropIfExists('ai_requests');
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('puc_expiry'));
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customer_push_subscriptions');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('payment_claims');
        Schema::dropIfExists('customer_otps');
        Schema::dropIfExists('customer_sessions');
    }
};
