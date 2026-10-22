<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — fleets (cab owners) and their drivers:
 *  - a fleet is a customer with is_fleet; its drivers sign in to the customer app and see only their cabs;
 *  - drivers approve extra work up to a total per job (master default, per-fleet override);
 *  - drivers report problems and log odometer readings; the owner gets a fleet dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_fleet')->default(false)->after('customer_type');
            // Null: the default in Settings › Fleet
            $table->decimal('fleet_driver_limit', 12, 2)->nullable()->after('is_fleet');
        });

        Schema::create('fleet_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            // staff | owner_app
            $table->string('created_via', 10)->default('staff');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('phone');
        });

        Schema::create('fleet_driver_vehicle', function (Blueprint $table) {
            $table->foreignId('fleet_driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->primary(['fleet_driver_id', 'vehicle_id']);
        });

        // Who is signed in / asked for a code / gets a notification: null = the customer (fleet owner)
        foreach (['customer_sessions', 'customer_otps', 'customer_notifications', 'customer_push_subscriptions', 'customer_links'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'dedupe_key']);
        });
        Schema::table('customer_notifications', function (Blueprint $table) {
            // Dedupe per recipient; the owner's rows get 0 so the unique key still holds (NULLs never clash)
            $table->unsignedBigInteger('recipient_key')->default(0)->after('fleet_driver_id');
            $table->unique(['customer_id', 'recipient_key', 'dedupe_key'], 'cust_notif_recipient_dedupe_unique');
        });

        Schema::table('job_cards', function (Blueprint $table) {
            // The driver who brought the cab in
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('job_card_estimate_lines', function (Blueprint $table) {
            $table->foreignId('decided_by_driver_id')->nullable()->after('decided_by')->constrained('fleet_drivers')->nullOnDelete();
        });

        Schema::table('job_card_estimate_decisions', function (Blueprint $table) {
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('fleet_reports', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            // Null: reported by the fleet owner
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // breakdown | warning_light | noise | accident | tyre | ac | other
            $table->string('kind', 15);
            $table->text('description');
            $table->unsignedInteger('odometer_km')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('location_text', 255)->nullable();
            // open | booked | job_opened | closed
            $table->string('status', 12)->default('open');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('handled_at')->nullable();
            $table->string('staff_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('fleet_report_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_report_id')->constrained()->cascadeOnDelete();
            // photo | video
            $table->string('media_type', 10);
            $table->string('file_disk', 30);
            $table->string('file_path');
            $table->string('mime', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_odometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('km');
            // driver | owner | job_card | staff
            $table->string('source', 10);
            $table->foreignId('fleet_driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo_disk', 30)->nullable();
            $table->string('photo_path')->nullable();
            $table->dateTime('read_at');
            $table->timestamps();

            $table->index(['vehicle_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_odometer_readings');
        Schema::dropIfExists('fleet_report_media');
        Schema::dropIfExists('fleet_reports');

        Schema::table('job_card_estimate_decisions', fn (Blueprint $t) => $t->dropConstrainedForeignId('fleet_driver_id'));
        Schema::table('job_card_estimate_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('decided_by_driver_id'));
        Schema::table('job_cards', fn (Blueprint $t) => $t->dropConstrainedForeignId('fleet_driver_id'));

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropUnique('cust_notif_recipient_dedupe_unique');
            $table->dropColumn('recipient_key');
        });
        Schema::table('customer_notifications', fn (Blueprint $t) => $t->unique(['customer_id', 'dedupe_key']));

        foreach (['customer_sessions', 'customer_otps', 'customer_notifications', 'customer_push_subscriptions', 'customer_links'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('fleet_driver_id'));
        }

        Schema::dropIfExists('fleet_driver_vehicle');
        Schema::dropIfExists('fleet_drivers');

        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn(['is_fleet', 'fleet_driver_limit']));
    }
};
