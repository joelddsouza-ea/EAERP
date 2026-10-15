<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing channel: login-free job links, the outgoing message log
 * (WhatsApp share / API), and the 360/ELITE service report release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 of the token, for lookup
            $table->text('token_encrypted'); // app-key encrypted, so later messages can repeat the link
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_card_id', 'revoked_at']);
        });

        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('template', 50);
            $table->text('body');
            $table->string('phone', 20)->nullable();
            $table->string('channel', 20); // whatsapp_share | whatsapp_api
            $table->string('status', 20)->default('prepared'); // prepared | sent | failed | dismissed
            $table->string('error')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['job_card_id', 'status']);
        });

        Schema::table('job_card_estimate_decisions', function (Blueprint $table) {
            $table->foreign('customer_message_id')->references('id')->on('customer_messages')->nullOnDelete();
        });

        Schema::create('job_card_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_card_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('include_activities')->default(true);
            $table->boolean('include_additional')->default(true);
            $table->boolean('include_observations')->default(true);
            $table->text('advisor_notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('job_card_observations', function (Blueprint $table) {
            $table->string('severity', 10)->nullable()->after('text'); // minor | medium | major
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('whatsapp_phone', 20)->nullable()->after('alt_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('whatsapp_phone'));
        Schema::table('job_card_observations', fn (Blueprint $table) => $table->dropColumn('severity'));
        Schema::dropIfExists('job_card_reports');
        Schema::table('job_card_estimate_decisions', fn (Blueprint $table) => $table->dropForeign(['customer_message_id']));
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('customer_links');
    }
};
