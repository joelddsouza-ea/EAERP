<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Billing\Models\GatePass;
use App\Modules\Workshop\Models\CustomerAddress;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Services\JobSheetService;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\JobSheetTemplatesSeeder;
use Database\Seeders\OilTypesSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServicePackagesSeeder;
use Database\Seeders\TaxRatesSeeder;
use Database\Seeders\VehicleCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 5: pickup & drop by a driver, and home service by a technician.
 */
class PickupDropHomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $advisor;
    private User $backoffice;
    private User $tech;
    private User $tech2;
    private User $driver;
    private int $branchId;
    private int $customerId;
    private int $vehicleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            OrganizationSeeder::class,
            RolesAndPermissionsSeeder::class,
            VehicleCategoriesSeeder::class,
            OilTypesSeeder::class,
            ServicePackagesSeeder::class,
            TaxRatesSeeder::class,
            JobSheetTemplatesSeeder::class,
            AdminUserSeeder::class,
        ]);

        Storage::fake('job_media');
        WorkflowStage::flushCache();

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->backoffice = User::where('email', 'backoffice@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->tech2 = User::where('email', 'tech2@expressautocare.in')->firstOrFail();
        $this->driver = User::where('email', 'driver@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Meera Kulkarni', 'phone' => '98200 11223']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH12AB1234',
            'category_id' => VehicleCategory::where('slug', 'hatchback')->value('id'),
            'fuel_type' => 'petrol',
        ]);
        $this->customerId = $customer->id;
        $this->vehicleId = $vehicle->id;
    }

    // ---------------------------------------------------------------- helpers

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function newJob(string $mode, string $plan = 'ea-refresh', array $trip = []): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'service_mode' => $mode,
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'package_id' => ServicePackage::where('slug', $plan)->value('id'),
            'oil_type_id' => OilType::where('slug', 'semi-synthetic')->value('id'),
            'service_type' => 'scheduled',
            'trip' => [
                'address' => 'Flat 4, Sai Krupa, Baner Road, Pune',
                'landmark' => 'Near the petrol pump',
                'slot_date' => today()->toDateString(),
                'slot_from' => '10:00',
                'slot_to' => '12:00',
                'save_address' => true,
                ...$trip,
            ],
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    private function trip(JobCard $job, string $kind): JobTrip
    {
        return JobTrip::where('job_card_id', $job->id)->where('kind', $kind)->latest('id')->firstOrFail();
    }

    private function upload(User $user, JobTrip $trip, string $purpose, string $type = 'photo', ?string $side = null)
    {
        $file = $type === 'video'
            ? UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4')
            : UploadedFile::fake()->image('car.jpg', 800, 600);

        return $this->as($user)->post("/api/v1/workshop/trips/{$trip->id}/media", array_filter([
            'file' => $file,
            'purpose' => $purpose,
            'side' => $side,
        ]), ['Accept' => 'application/json']);
    }

    /**
     * Plan-only estimate sent, the job ready for delivery, the bill approved and paid in full.
     */
    private function billAndPay(JobCard $job, User $payer): void
    {
        $base = "/api/v1/workshop/job-cards/{$job->id}";
        $estimate = $this->as($this->advisor)->postJson("{$base}/estimates")->assertOk()->json('data');
        $this->as($this->advisor)->postJson("{$base}/estimates/{$estimate['id']}/send")->assertOk();

        $job->refresh()->update(['status' => 'ready_for_delivery']);
        $bill = $this->as($this->backoffice)->postJson("{$base}/billing/proforma")->assertCreated()->json('data.bill');
        $summary = $this->as($this->backoffice)->postJson("{$base}/billing/bills/{$bill['id']}/approve")->assertOk()->json('data');
        $this->assertSame('invoiced', $job->fresh()->status);

        $this->as($payer)->postJson("{$base}/billing/payments", ['amount' => $summary['balance'], 'method' => 'cash'])->assertCreated();
        $this->assertSame('gate_pass_generated', $job->fresh()->status);
    }

    // ------------------------------------------------------------------ tests

    public function test_pickup_job_is_booked_with_driver_and_policy_message(): void
    {
        $job = $this->newJob('pickup_drop', trip: ['assigned_to' => $this->driver->id, 'charge' => true]);

        $this->assertSame('pickup_scheduled', $job->status);
        $this->assertSame('pickup_drop', $job->service_mode);
        $this->assertNull($job->km_reading);
        $this->assertNull($job->time_in);

        $pickup = $this->trip($job, 'pickup');
        $this->assertSame('scheduled', $pickup->status);
        $this->assertSame($this->driver->id, $pickup->assigned_to);
        $drop = $this->trip($job, 'drop');
        $this->assertSame('unassigned', $drop->status);
        $this->assertSame($pickup->address, $drop->address);

        // The pickup address is saved for next time
        $this->assertSame(1, CustomerAddress::where('customer_id', $this->customerId)->count());

        // The driver hears about it; the customer gets the booking message with the slot and policy
        $this->assertSame('trip_assigned', $this->driver->notifications()->latest()->first()->data['kind']);
        $message = CustomerMessage::where('job_card_id', $job->id)->where('template', 'stage:pickup_scheduled')->sole();
        $this->assertStringContainsString('between 10:00 and 12:00', $message->body);
        $this->assertStringContainsString('Pickup & drop policy', $message->body);

        // Drive-in still needs the odometer on the form; a pickup needs an address
        $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $this->customerId, 'vehicle_id' => $this->vehicleId, 'service_type' => 'general_repair',
        ])->assertStatus(422)->assertJsonValidationErrors(['km_reading', 'fuel_level']);
        $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'service_mode' => 'pickup_drop', 'customer_id' => $this->customerId, 'vehicle_id' => $this->vehicleId, 'service_type' => 'general_repair',
        ])->assertStatus(422)->assertJsonValidationErrors(['trip']);
    }

    public function test_driver_pickup_is_the_intake_and_the_job_moves_on_by_itself(): void
    {
        $job = $this->newJob('pickup_drop', trip: ['assigned_to' => $this->driver->id]);
        $job->update(['technician_id' => $this->tech->id]);
        $pickup = $this->trip($job, 'pickup');

        // Drivers see only their trips, not the job card list
        $this->as($this->driver)->getJson('/api/v1/workshop/job-cards')->assertForbidden();
        $this->as($this->driver)->getJson('/api/v1/workshop/trips/mine')->assertOk()->assertJsonPath('data.0.id', $pickup->id);

        // Trip steps are not on the job card's status menu
        $this->as($this->advisor)->patchJson("/api/v1/workshop/job-cards/{$job->id}/status", ['status' => 'pickup_en_route'])->assertStatus(422);

        // Someone else's trip
        $other = User::create(['name' => 'Driver Two', 'email' => 'driver2@example.com', 'phone' => '9000000002', 'password' => 'x', 'is_active' => true]);
        $other->roles()->attach(\App\Core\Models\Role::where('slug', 'driver')->value('id'));
        $other->branches()->attach($this->branchId, ['is_primary' => true]);
        $this->as($other)->postJson("/api/v1/workshop/trips/{$pickup->id}/start")->assertForbidden();

        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/start")->assertOk()->assertJsonPath('data.status', 'en_route');
        $this->assertSame('pickup_en_route', $job->fresh()->status);
        $this->assertTrue(CustomerMessage::where('job_card_id', $job->id)->where('template', 'stage:pickup_en_route')
            ->where('body', 'like', '%Driver One%')->exists());

        // Not without odometer, fuel and the minimum photos/videos
        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/collect", ['km_reading' => 42000, 'fuel_level' => 'half'])
            ->assertStatus(422);

        // No upper limit on photos / videos
        $photos = IntakeCaptured::minPhotos() + 3;
        for ($i = 0; $i < $photos; $i++) {
            $this->upload($this->driver, $pickup, 'intake')->assertCreated();
        }
        $this->upload($this->driver, $pickup, 'intake', 'video')->assertCreated();
        $this->upload($this->driver, $pickup, 'intake', 'video')->assertCreated();
        // Drop photos are not taken on a pickup
        $this->upload($this->driver, $pickup, 'handover')->assertStatus(422);

        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/collect", [
            'km_reading' => 42000, 'fuel_level' => 'half', 'notes' => 'Brakes squeal a little', 'handover_name' => 'Mr Kulkarni',
        ])->assertOk()->assertJsonPath('data.status', 'collected');

        $job->refresh();
        $this->assertSame('vehicle_collected', $job->status);
        $this->assertSame(42000, $job->km_reading);
        // The driver's photos are the intake, but the trial drive is the advisor's, at the workshop
        $this->assertNull($job->intake_trial_status);
        $this->assertStringContainsString('Brakes squeal', $pickup->fresh()->notes);

        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/reach-workshop")->assertOk()->assertJsonPath('data.status', 'done');
        $job->refresh();
        $this->assertSame('intake_in_progress', $job->status);
        // No new photos needed: the advisor records the trial drive and work starts
        $this->as($this->advisor)->putJson("/api/v1/workshop/job-cards/{$job->id}/intake/trial", ['status' => 'done'])->assertOk();
        $job->refresh();
        $this->assertSame('work_in_progress', $job->status);
        $this->assertNotNull($job->time_in);
        $this->assertSame(['photo' => $photos, 'video' => 2], IntakeCaptured::mediaCounts($job));

        // The customer's gallery shows the pickup photos under arrival
        $this->as($this->advisor)->getJson("/api/v1/workshop/job-cards/{$job->id}/intake")->assertOk()
            ->assertJsonPath('data.trial.by', $this->advisor->name);
    }

    public function test_drop_needs_the_gate_pass_and_four_photos(): void
    {
        $job = $this->newJob('pickup_drop', trip: ['assigned_to' => $this->driver->id]);
        $drop = $this->trip($job, 'drop');

        // Advisor books the drop with a charge from the masters
        $this->as($this->owner)->putJson('/api/v1/workshop/trip-settings', ['drop_charge' => 299])->assertOk();
        $this->as($this->advisor)->putJson('/api/v1/workshop/trip-settings', ['drop_charge' => 1])->assertForbidden();
        $this->as($this->advisor)->patchJson("/api/v1/workshop/trips/{$drop->id}", [
            'assigned_to' => $this->driver->id, 'slot_date' => today()->toDateString(), 'with_charge' => true,
        ])->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.charge', 299);

        // Move the job to the end of the work (pickup done by hand here)
        $job->update(['status' => 'qc_pending']);
        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$drop->id}/start")->assertStatus(422);

        $this->billAndPay($job, $this->advisor);
        $bill = \App\Modules\Billing\Models\Invoice::where('job_card_id', $job->id)->latest('id')->firstOrFail();
        $line = $bill->items()->where('section', 'trips')->sole();
        $this->assertSame('Drop charge', $line->description);
        $this->assertEquals(299, $line->total);

        // Start = the car leaves on its gate pass; the job is not delivered until the handover
        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$drop->id}/start")->assertOk();
        $this->assertNotNull(GatePass::where('job_card_id', $job->id)->sole()->vehicle_out_at);
        $this->assertSame('gate_pass_generated', $job->fresh()->status);
        $this->assertTrue(CustomerMessage::where('job_card_id', $job->id)->where('template', 'drop_en_route')->exists());

        foreach (['front', 'rear', 'left'] as $side) {
            $this->upload($this->driver, $drop, 'handover', side: $side)->assertCreated();
        }
        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$drop->id}/deliver")->assertStatus(422)
            ->assertJsonPath('errors.photos_needed', 1);
        $this->upload($this->driver, $drop, 'handover', side: 'right')->assertCreated();

        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$drop->id}/deliver", ['handover_name' => 'Meera'])->assertOk();
        $this->assertSame('delivered', $job->fresh()->status);

        // The customer sees the drop photos
        $token = \App\Modules\Workshop\Models\CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $areas = collect($this->getJson("/api/v1/public/j/{$token}")->assertOk()->json('data.gallery'));
        $this->assertSame(4, $areas->firstWhere('key', 'handover')['count'] ?? null);
    }

    public function test_drop_not_needed_when_the_customer_collects_at_the_workshop(): void
    {
        $job = $this->newJob('pickup_drop');
        $job->update(['status' => 'qc_pending']);
        $this->billAndPay($job, $this->advisor);

        $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$job->id}/billing/gate-pass/vehicle-out")->assertOk();
        $this->assertSame('delivered', $job->fresh()->status);
        $this->assertSame('cancelled', $this->trip($job, 'drop')->status);
    }

    public function test_cancelling_the_pickup_makes_it_a_workshop_job(): void
    {
        $job = $this->newJob('pickup_drop', trip: ['assigned_to' => $this->driver->id]);
        $pickup = $this->trip($job, 'pickup');

        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->as($this->advisor)->postJson("/api/v1/workshop/trips/{$pickup->id}/cancel", ['reason' => 'Customer will drive in'])->assertOk();

        $this->assertSame('cancelled', $pickup->fresh()->status);
        $this->assertSame('intake_pending', $job->fresh()->status);
        // The drop stays booked
        $this->assertSame('unassigned', $this->trip($job, 'drop')->status);
    }

    public function test_driver_can_turn_back(): void
    {
        $job = $this->newJob('pickup_drop', trip: ['assigned_to' => $this->driver->id]);
        $pickup = $this->trip($job, 'pickup');
        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/start")->assertOk();

        $this->as($this->driver)->postJson("/api/v1/workshop/trips/{$pickup->id}/turn-back", ['reason' => 'Customer not at home'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame('pickup_scheduled', $job->fresh()->status);
    }

    public function test_home_service_from_booking_to_closing(): void
    {
        // EA 360 is not offered at home
        $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'service_mode' => 'home', 'customer_id' => $this->customerId, 'vehicle_id' => $this->vehicleId,
            'package_id' => ServicePackage::where('slug', 'ea-360')->value('id'),
            'oil_type_id' => OilType::where('slug', 'semi-synthetic')->value('id'),
            'service_type' => 'scheduled',
            'trip' => ['address' => 'Somewhere', 'slot_date' => today()->toDateString()],
        ])->assertStatus(422)->assertJsonValidationErrors(['service_mode']);

        $job = $this->newJob('home', 'ea-essentials', ['assigned_to' => $this->tech->id]);
        $this->assertSame('home_visit_scheduled', $job->status);
        $this->assertSame($this->tech->id, $job->technician_id);
        $this->assertFalse(JobTrip::where('job_card_id', $job->id)->where('kind', 'drop')->exists());

        // Workshop-only activities (body wash…) are set aside on the home visit
        $skipped = JobCardChecklistItem::whereIn('job_card_checklist_id', $job->checklists()->pluck('id'))
            ->where('remarks', JobSheetService::HOME_SKIP_REMARK)->pluck('item_name');
        $this->assertTrue($skipped->contains(fn ($n) => stripos($n, 'body wash') !== false));
        $this->assertTrue($skipped->every(fn ($n) => stripos($n, 'wash') !== false || stripos($n, 'align') !== false));

        $visit = $this->trip($job, 'home_visit');
        $this->as($this->tech2)->postJson("/api/v1/workshop/trips/{$visit->id}/start")->assertForbidden();
        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/start")->assertOk();
        $this->assertSame('home_visit_en_route', $job->fresh()->status);

        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/arrive")->assertOk()->assertJsonPath('data.status', 'arrived');
        $this->assertSame('intake_in_progress', $job->fresh()->status);
        $this->assertSame('skipped', $job->fresh()->intake_trial_status);

        for ($i = 0; $i < IntakeCaptured::minPhotos(); $i++) {
            $this->upload($this->tech, $visit, 'intake')->assertCreated();
        }
        for ($i = 0; $i < IntakeCaptured::minVideos(); $i++) {
            $this->upload($this->tech, $visit, 'intake', 'video')->assertCreated();
        }
        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/readings", ['km_reading' => 15000, 'fuel_level' => 'full'])->assertOk();
        $this->assertSame('work_in_progress', $job->fresh()->status);

        // Trial drive and wash are on the job sheet (workshop-only activities are N/A on a home visit)

        // Close needs the bill paid
        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/close")->assertStatus(422);

        $job->update(['status' => 'qc_pending']);
        $base = "/api/v1/workshop/job-cards/{$job->id}";
        $estimate = $this->as($this->advisor)->postJson("{$base}/estimates")->assertOk()->json('data');
        $this->as($this->advisor)->postJson("{$base}/estimates/{$estimate['id']}/send")->assertOk();
        $job->refresh()->update(['status' => 'ready_for_delivery']);
        $bill = $this->as($this->backoffice)->postJson("{$base}/billing/proforma")->assertCreated()->json('data.bill');
        $summary = $this->as($this->backoffice)->postJson("{$base}/billing/bills/{$bill['id']}/approve")->assertOk()->json('data');

        // The technician collects the payment on site
        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/payment", ['method' => 'upi', 'amount' => $summary['balance'], 'reference_number' => 'UPI9'])
            ->assertOk();
        $this->assertSame('gate_pass_generated', $job->fresh()->status);

        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/close")->assertStatus(422);
        $this->upload($this->tech, $visit, 'handover')->assertCreated();
        $this->upload($this->tech, $visit, 'handover')->assertCreated();
        $this->as($this->tech)->postJson("/api/v1/workshop/trips/{$visit->id}/close")->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame('delivered', $job->fresh()->status);
    }

    public function test_home_visit_cancelled_becomes_a_workshop_job_with_all_activities(): void
    {
        $job = $this->newJob('home', 'ea-essentials', ['assigned_to' => $this->tech->id]);
        $visit = $this->trip($job, 'home_visit');

        $this->as($this->advisor)->postJson("/api/v1/workshop/trips/{$visit->id}/cancel", ['reason' => 'Customer bringing the car'])->assertOk();

        $job->refresh();
        $this->assertSame('intake_pending', $job->status);
        $this->assertSame('drive_in', $job->service_mode);
        $this->assertSame(0, JobCardChecklistItem::whereIn('job_card_checklist_id', $job->checklists()->pluck('id'))
            ->where('remarks', JobSheetService::HOME_SKIP_REMARK)->count());
    }

    public function test_board_lists_todays_trips_for_advisors(): void
    {
        $this->newJob('pickup_drop', trip: ['assigned_to' => $this->driver->id]);

        $data = $this->as($this->advisor)->getJson('/api/v1/workshop/trips/board')->assertOk()->json('data');
        $this->assertCount(2, $data['trips']); // today's pickup + the drop not booked yet
        $this->assertTrue(collect($data['people'])->contains(fn ($p) => $p['id'] === $this->driver->id && $p['is_driver']));

        $this->as($this->driver)->getJson('/api/v1/workshop/trips/board')->assertForbidden();
    }
}
