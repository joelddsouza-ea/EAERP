<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8:
 *  - live staff location: points sent by the phone between punch in and punch out, the
 *    latest position per employee, a per-employee switch and the one-time consent;
 *  - WhatsApp Business API (Interakt): which approved template each message kind uses,
 *    and on each message the values that fill it plus the provider's delivery status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            // The phone's time of the fix; received_at is when it reached us (late when offline)
            $table->dateTime('recorded_at');
            $table->dateTime('received_at');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_m', 8, 1)->nullable();
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->boolean('is_charging')->nullable();
            // web = browser / installed web app, android = the Android app (works in the background)
            $table->string('source', 10)->default('web');
            $table->foreignId('job_trip_id')->nullable()->constrained('job_trips')->nullOnDelete();

            $table->unique(['user_id', 'recorded_at']);
            $table->index('work_date');
        });

        Schema::create('staff_live_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy_m', 8, 1)->nullable();
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->dateTime('recorded_at')->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->string('source', 10)->nullable();
            // active = sharing, stopped = punched out; gap_reason = why the phone isn't sending
            $table->string('state', 10)->default('stopped');
            $table->string('gap_reason', 30)->nullable();
            $table->dateTime('gap_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('stopped_at')->nullable();
            $table->foreignId('job_trip_id')->nullable()->constrained('job_trips')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->boolean('track_location')->default(true)->after('attendance_required');
            $table->dateTime('location_consent_at')->nullable()->after('track_location');
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            // Our message kind, e.g. "stage:ready", "estimate_sent", "crm:booking_confirmed"
            $table->string('key', 60)->unique();
            $table->string('label', 120);
            // The template's name as approved in Interakt (blank = not mapped: share link as before)
            $table->string('provider_name', 120)->nullable();
            $table->string('language', 10)->default('en');
            // Our placeholders in the order of the template's {{1}}, {{2}} …
            $table->json('body_params')->nullable();
            // none | image | video | document (header media from the message, e.g. a creative)
            $table->string('header_type', 10)->default('none');
            // Placeholder that fills the dynamic URL button, if the template has one
            $table->string('button_param', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('customer_messages', function (Blueprint $table) {
            $table->json('params')->nullable()->after('body');
            $table->string('provider_message_id', 100)->nullable()->after('channel');
            $table->dateTime('delivered_at')->nullable()->after('sent_at');
            $table->dateTime('read_at')->nullable()->after('delivered_at');

            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['params', 'provider_message_id', 'delivered_at', 'read_at']);
        });
        Schema::dropIfExists('whatsapp_templates');
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['track_location', 'location_consent_at']);
        });
        Schema::dropIfExists('staff_live_status');
        Schema::dropIfExists('staff_locations');
    }
};
