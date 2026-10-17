<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 7 — CRM: where leads come from, the leads and their timeline, campaigns and ad
 * spend, lost reasons, booking slots and bookings (appointments that become job cards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 40)->unique();
            // Ads cost money: cost per lead / booking is worked out from campaign spends
            $table->boolean('is_paid')->default(false);
            // Secret part of the webhook URL that posts leads from this source
            $table->string('webhook_key', 48)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_source_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            // Campaign id / name as the ad platform sends it, matched on incoming leads
            $table->string('external_ref', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('external_ref');
        });

        Schema::create('campaign_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'period']);
        });

        Schema::create('lost_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            // Keep nurturing the lead (e.g. "not now") instead of closing the door
            $table->boolean('nurture')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            // Normalised (91XXXXXXXXXX) for WhatsApp and duplicate checks
            $table->string('phone', 20);
            $table->string('alt_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('city', 80)->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tracking')->nullable(); // utm / ad / form ids as received
            $table->foreignId('referred_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            // The car as told by the lead (filled into a vehicle on booking)
            $table->string('car_text', 120)->nullable();
            $table->string('registration_no', 20)->nullable();
            $table->foreignId('make_id')->nullable()->constrained('vehicle_makes')->nullOnDelete();
            $table->foreignId('model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('vehicle_categories')->nullOnDelete();
            $table->string('fuel_type', 20)->nullable();
            // What they want: a plan, or a repair type (service_type values)
            $table->string('interest', 30)->nullable();
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            $table->text('requirement')->nullable();
            // new_enquiry | service_due | pending_work | win_back | insurance_renewal | feedback
            $table->string('kind', 20)->default('new_enquiry');
            // new | contacted | interested | booked | won | lost | unreachable
            $table->string('status', 12)->default('new');
            // hot | warm | cold
            $table->string('temperature', 5)->default('warm');
            $table->smallInteger('score')->default(50);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_contact_due_at')->nullable();
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('sla_alerted_at')->nullable();
            $table->timestamp('sla_escalated_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->unsignedTinyInteger('unanswered_calls')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('lost_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lost_note', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            // Billed value of the job the lead turned into
            $table->decimal('won_revenue', 12, 2)->default(0);
            $table->foreignId('won_job_card_id')->nullable()->constrained('job_cards')->nullOnDelete();
            $table->timestamp('opted_out_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['phone', 'status']);
            $table->index(['assigned_to', 'status', 'next_follow_up_at']);
            $table->index(['branch_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // created | enquired_again | call | whatsapp | note | status | quote | booking | assigned | link_opened | reply | message
            $table->string('type', 20);
            // connected | no_answer | busy | call_back | not_interested | booked (calls)
            $table->string('outcome', 20)->nullable();
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('booking_slots', function (Blueprint $table) {
            $table->id();
            // null = every branch
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            // 0 = Sunday … 6 = Saturday
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('capacity')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'weekday']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('number', 24)->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->date('booking_date');
            $table->time('slot_from')->nullable();
            $table->time('slot_to')->nullable();
            // scheduled + package, or a repair type
            $table->string('service_type', 30)->default('scheduled');
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
            $table->foreignId('oil_type_id')->nullable()->constrained('oil_types')->nullOnDelete();
            // drive_in | pickup_drop | home
            $table->string('service_mode', 12)->default('drive_in');
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->text('notes')->nullable();
            // booked | confirmed | arrived | converted | no_show | cancelled
            $table->string('status', 12)->default('booked');
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            // staff | self (customer's booking link) | web
            $table->string('booked_via', 8)->default('staff');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'booking_date', 'status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique()->after('lead_id');
            $table->timestamp('marketing_opt_out_at')->nullable()->after('referral_code');
            $table->date('birthday')->nullable()->after('marketing_opt_out_at');
        });

        $sources = [
            ['Meta ads (Facebook / Instagram)', 'meta', true],
            ['Google ads', 'google', true],
            ['Click-to-WhatsApp ad', 'whatsapp_ad', true],
            ['Referral', 'referral', false],
            ['Incoming call', 'call', false],
            ['Walk-in', 'walk_in', false],
            ['Website', 'website', false],
            ['Existing customer', 'existing', false],
            ['Other', 'other', false],
        ];
        foreach ($sources as $i => [$name, $slug, $paid]) {
            DB::table('lead_sources')->insert([
                'name' => $name, 'slug' => $slug, 'is_paid' => $paid, 'webhook_key' => Str::random(32),
                'is_active' => true, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $reasons = [
            ['Price too high', false], ['Went to another garage', false], ['Not now — later', true], ['Sold the car', false],
            ['Only enquiring', true], ['Not reachable', true], ['Outside our area', false], ['Wrong number / spam', false],
        ];
        foreach ($reasons as $i => [$name, $nurture]) {
            DB::table('lost_reasons')->insert(['name' => $name, 'nurture' => $nurture, 'is_active' => true, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Monday–Saturday, 3 cars per slot
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            foreach ([['09:30', '11:00'], ['11:00', '12:30'], ['12:30', '14:00'], ['14:00', '15:30'], ['15:30', '17:00']] as [$from, $to]) {
                DB::table('booking_slots')->insert(['branch_id' => null, 'weekday' => $day, 'start_time' => $from, 'end_time' => $to, 'capacity' => 3, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'marketing_opt_out_at', 'birthday']);
        });
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_slots');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lost_reasons');
        Schema::dropIfExists('campaign_spends');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('lead_sources');
    }
};
