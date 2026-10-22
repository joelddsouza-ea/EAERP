<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 11 — Interakt inside the CRM:
 *  - WhatsApp conversations (one per number) and their messages, both ways: the CRM inbox;
 *  - every Interakt webhook kept (debugging, "last received" per type);
 *  - contacts and events queued for Interakt's Track APIs;
 *  - templates know their approval status, buttons and what a quick-reply tap does;
 *  - a campaign can be recognised by the first text of a Click-to-WhatsApp chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            // 91XXXXXXXXXX
            $table->string('phone', 15)->unique();
            $table->string('name', 120)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // The 24-hour window for free-form replies runs from the customer's last message
            $table->dateTime('last_inbound_at')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->string('last_preview', 200)->nullable();
            $table->unsignedInteger('unread')->default(0);
            // open | closed
            $table->string('status', 10)->default('open');
            $table->string('first_text', 1000)->nullable();
            // Whatever Interakt sent about where the chat came from (ads), kept as is
            $table->json('referral')->nullable();
            $table->string('interakt_customer_id', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('whatsapp_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            // in | out
            $table->string('direction', 3);
            // text | image | document | video | audio | template | button | other
            $table->string('kind', 10)->default('text');
            $table->text('body')->nullable();
            $table->string('media_url', 1000)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('provider_message_id', 100)->nullable();
            // A template message made elsewhere (job updates, journeys): its row in customer_messages
            $table->foreignId('customer_message_id')->nullable()->constrained()->nullOnDelete();
            // queued | sent | delivered | read | failed | received
            $table->string('status', 10)->default('queued');
            $table->string('error', 300)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->index('provider_message_id');
        });

        Schema::create('interakt_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->string('result', 60)->nullable();
            $table->string('error', 500)->nullable();
            $table->json('payload')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });

        Schema::create('interakt_outbox', function (Blueprint $table) {
            $table->id();
            // user | event
            $table->string('kind', 5);
            // lead:12 / customer:5 — the latest user row for a subject replaces older unsent ones
            $table->string('subject', 40);
            $table->string('event', 60)->nullable();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_try_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['sent_at', 'next_try_at']);
            $table->index(['subject', 'kind']);
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            // APPROVED | PENDING | REJECTED | … as Interakt reports it
            $table->string('approval_status', 20)->nullable()->after('is_active');
            // UTILITY | MARKETING | AUTHENTICATION
            $table->string('category', 20)->nullable()->after('approval_status');
            // The template's buttons as fetched: [{type, text}]
            $table->json('buttons')->nullable()->after('category');
            // Quick-reply button text => what a tap does (lead actions only)
            $table->json('button_actions')->nullable()->after('buttons');
            $table->dateTime('synced_at')->nullable()->after('button_actions');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            // The ready-made first message of the campaign's Click-to-WhatsApp ad ("Hi, I want the monsoon offer")
            $table->string('whatsapp_start_text', 200)->nullable();
        });

        foreach (['leads', 'customers'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dateTime('interakt_synced_at')->nullable());
        }

        if (!DB::table('lead_sources')->where('slug', 'whatsapp')->exists()) {
            DB::table('lead_sources')->insert([
                'name' => 'WhatsApp chat',
                'slug' => 'whatsapp',
                'is_paid' => false,
                'webhook_key' => Str::random(32),
                'is_active' => true,
                'sort_order' => (int) DB::table('lead_sources')->max('sort_order') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('lead_sources')->where('slug', 'whatsapp')->whereNotExists(fn ($q) => $q->from('leads')->whereColumn('leads.lead_source_id', 'lead_sources.id'))->delete();
        foreach (['leads', 'customers'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('interakt_synced_at'));
        }
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('whatsapp_start_text'));
        Schema::table('whatsapp_templates', fn (Blueprint $t) => $t->dropColumn(['approval_status', 'category', 'buttons', 'button_actions', 'synced_at']));
        Schema::dropIfExists('interakt_outbox');
        Schema::dropIfExists('interakt_webhook_events');
        Schema::dropIfExists('whatsapp_chat_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
