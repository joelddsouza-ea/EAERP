<?php

use App\Modules\Workshop\Models\ChecklistItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — pickup / drop and home service:
 *  - a job's mode: drive-in, pickup & drop, or home service;
 *  - trips: each pickup, drop or home visit, with address, slot, the driver (or home-service
 *    technician), progress times and an optional charge that goes on the bill;
 *  - customers' saved addresses (pickup spots);
 *  - activities that can only be done at the workshop (left off home-service sheets);
 *  - job stages for the pickup and home-visit statuses (messages editable in the masters).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_cards', function (Blueprint $table) {
            // drive_in | pickup_drop | home
            $table->string('service_mode', 12)->default('drive_in')->after('service_type');
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->text('address');
            $table->string('landmark')->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('job_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_card_id')->constrained()->cascadeOnDelete();
            // pickup | drop | home_visit
            $table->string('kind', 12);
            // unassigned | scheduled | en_route | arrived | done | cancelled
            $table->string('status', 12)->default('unassigned');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->date('slot_date')->nullable();
            $table->time('slot_from')->nullable();
            $table->time('slot_to')->nullable();
            // GST-inclusive; 0 = free. Goes on the bill as a service line.
            $table->decimal('charge', 10, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Who handed over / received the car
            $table->string('handover_name', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status', 'slot_date']);
            $table->index(['job_card_id', 'kind']);
        });

        Schema::table('checklist_items', function (Blueprint $table) {
            // Only possible at the workshop (body wash, wheel alignment…): left off home-service sheets
            $table->boolean('workshop_only')->default(false)->after('na_allowed');
        });

        foreach (DB::table('checklist_items')->get(['id', 'item_name']) as $item) {
            if (ChecklistItem::looksWorkshopOnly($item->item_name)) {
                DB::table('checklist_items')->where('id', $item->id)->update(['workshop_only' => true]);
            }
        }

        $now = now();
        foreach (self::stages() as $i => $stage) {
            DB::table('workflow_stages')->updateOrInsert(
                ['status' => $stage['status']],
                [...$stage, 'auto' => false, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('workflow_stages')->whereIn('status', array_column(self::stages(), 'status'))->delete();

        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropColumn('workshop_only');
        });
        Schema::dropIfExists('job_trips');
        Schema::dropIfExists('customer_addresses');
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropColumn('service_mode');
        });
    }

    /**
     * Defaults; the owner edits the messages in Job Sheet Masters › Job stages.
     */
    public static function stages(): array
    {
        return [
            [
                'status' => 'pickup_scheduled',
                'label' => 'Pickup Scheduled',
                'waiting_for' => 'The driver taps Start on the pickup.',
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, your pickup for :car is booked with :workshop (Job :job).\n:slot\n:policy\nTrack your car here: :link",
            ],
            [
                'status' => 'pickup_en_route',
                'label' => 'Pickup En Route',
                'waiting_for' => 'The driver records the odometer, fuel and photos/videos and taps Collected.',
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, our driver :driver (:driver_phone) is on the way to pick up :car.",
            ],
            [
                'status' => 'vehicle_collected',
                'label' => 'Vehicle Collected',
                'waiting_for' => 'The driver reaches the workshop and taps Reached workshop.',
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, :driver has collected your car :car. Photos and videos taken at pickup are here: :link",
            ],
            [
                'status' => 'home_visit_scheduled',
                'label' => 'Home Visit Scheduled',
                'waiting_for' => 'The technician taps Start on the home visit.',
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, your home service for :car is booked with :workshop (Job :job).\n:slot\n:policy\nTrack it here: :link",
            ],
            [
                'status' => 'home_visit_en_route',
                'label' => 'Home Visit En Route',
                'waiting_for' => 'The technician reaches the customer and taps Arrived.',
                'notify_customer' => 'first_entry',
                'message' => "Hello :name, our technician :driver (:driver_phone) is on the way for the service of :car.",
            ],
        ];
    }
};
