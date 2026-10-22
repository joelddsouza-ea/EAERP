<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Booking;
use App\Modules\CustomerApp\Models\CustomerNotification;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetReport;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Services\CustomerLinkService;
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10: fleets — drivers sign in and see only their cabs, approve up to the fleet's
 * limit per job (the rest goes to the owner), report problems, log km; the owner's dashboard.
 */
class FleetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $advisor;
    private int $branchId;
    private Customer $fleet;
    private Vehicle $cab1;
    private Vehicle $cab2;
    private FleetDriver $ramesh;

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
        WorkshopSettings::set('fleet.driver_limit', 2000);

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $this->fleet = Customer::create(['name' => 'Sunil Patil', 'company_name' => 'Patil Cabs', 'customer_type' => 'corporate', 'phone' => '9820055500', 'is_fleet' => true]);
        $hatch = VehicleCategory::where('slug', 'hatchback')->value('id');
        $this->cab1 = Vehicle::create(['customer_id' => $this->fleet->id, 'registration_no' => 'MH12CB0001', 'category_id' => $hatch, 'fuel_type' => 'cng', 'next_service_due_km' => 60000]);
        $this->cab2 = Vehicle::create(['customer_id' => $this->fleet->id, 'registration_no' => 'MH12CB0002', 'category_id' => $hatch, 'fuel_type' => 'cng']);

        $this->ramesh = $this->addDriver('Ramesh Jadhav', '9876500011', [$this->cab1->id]);
    }

    // ---------------------------------------------------------------- helpers

    private function staff(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function app(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Branch-Id' => '']);
    }

    private function addDriver(string $name, string $phone, array $cabs): FleetDriver
    {
        $id = $this->staff($this->advisor)->postJson("/api/v1/fleet/customers/{$this->fleet->id}/drivers", [
            'name' => $name, 'phone' => $phone, 'vehicle_ids' => $cabs,
        ])->assertOk()->json('data.id');

        return FleetDriver::findOrFail($id);
    }

    private function newJob(Vehicle $cab, ?int $driverId = null): JobCard
    {
        $id = $this->staff($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'service_mode' => 'drive_in',
            'customer_id' => $this->fleet->id,
            'vehicle_id' => $cab->id,
            'package_id' => ServicePackage::where('slug', 'ea-refresh')->value('id'),
            'oil_type_id' => OilType::where('slug', 'semi-synthetic')->value('id'),
            'service_type' => 'scheduled',
            'km_reading' => 58000,
            'fuel_level' => 'half',
            'fleet_driver_id' => $driverId,
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    /** A sent estimate with additional lines (amounts in ₹), the job waiting for approval */
    private function estimate(JobCard $job, array $amounts): array
    {
        $estimate = JobCardEstimate::forceCreate(['job_card_id' => $job->id, 'version' => 1, 'status' => JobCardEstimate::STATUS_SENT]);
        $keys = [];
        foreach ($amounts as $i => $amount) {
            $keys[] = JobCardEstimateLine::forceCreate([
                'estimate_id' => $estimate->id, 'line_key' => (string) Str::uuid(), 'section' => 'additional',
                'kind' => 'part', 'description' => "Item {$i}", 'quantity' => 1, 'rate' => $amount, 'amount' => $amount,
                'approval_status' => 'pending', 'sort_order' => $i,
            ])->line_key;
        }
        $job->forceFill(['status' => 'awaiting_approval'])->save();

        return $keys;
    }

    /** Signed in with the driver's mobile number and the code staff read out */
    private function signInDriver(FleetDriver $driver): string
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => $driver->phone])->assertOk();
        $code = $this->staff($this->advisor)->getJson("/api/v1/customer-app/customers/{$this->fleet->id}/sign-in-code")->assertOk()->json('data.code');
        $this->assertSame($driver->name, $code['driver']);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/customer/auth/verify', ['phone' => $driver->phone, 'code' => $code['code']])->assertOk()->json('data.token');
    }

    private function signInOwner(JobCard $job): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/customer/auth/link', ['kind' => 'job', 'token' => app(CustomerLinkService::class)->ensure($job)->token])->assertOk()->json('data.token');
    }

    private function decide(string $pageToken, array $decisions)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/v1/public/j/{$pageToken}/decisions", [
            'decisions' => collect($decisions)->map(fn ($d, $k) => ['line_key' => $k, 'decision' => $d])->values()->all(),
        ]);
    }

    // ---------------------------------------------------------------- tests

    public function test_driver_signs_in_and_sees_only_their_cabs(): void
    {
        $job1 = $this->newJob($this->cab1, $this->ramesh->id);
        $job2 = $this->newJob($this->cab2);

        $token = $this->signInDriver($this->ramesh);
        $home = $this->app($token)->getJson('/api/v1/customer/home')->assertOk();
        $home->assertJsonPath('data.viewer.role', 'driver')
            ->assertJsonPath('data.customer.name', 'Ramesh Jadhav')
            ->assertJsonCount(1, 'data.vehicles')
            ->assertJsonPath('data.vehicles.0.registration_no', 'MH12CB0001')
            ->assertJsonCount(1, 'data.active_jobs');

        $this->app($token)->getJson("/api/v1/customer/jobs/{$job1->id}")->assertOk();
        $this->app($token)->getJson("/api/v1/customer/jobs/{$job2->id}")->assertNotFound();
        $this->app($token)->getJson("/api/v1/customer/vehicles/{$this->cab2->id}")->assertNotFound();

        // Owner screens are not for drivers
        $this->app($token)->getJson('/api/v1/customer/wallet')->assertForbidden();
        $this->app($token)->getJson('/api/v1/customer/fleet')->assertForbidden();
        $this->app($token)->postJson('/api/v1/customer/vehicles', ['registration_no' => 'MH12ZZ9999'])->assertForbidden();

        // Switched off by the owner: signed out
        $this->staff($this->advisor)->putJson("/api/v1/fleet/customers/{$this->fleet->id}/drivers/{$this->ramesh->id}", ['is_active' => false])->assertOk();
        $this->app($token)->getJson('/api/v1/customer/home')->assertUnauthorized();
    }

    public function test_one_number_with_two_accounts_asks_which(): void
    {
        // Ramesh also has a car of his own
        $own = Customer::create(['name' => 'Ramesh Jadhav', 'phone' => '98765 00011']);

        $this->postJson('/api/v1/customer/auth/code', ['phone' => '9876500011'])->assertOk();
        $code = \App\Modules\CustomerApp\Models\CustomerOtp::latest('id')->first()->code();
        $choice = $this->postJson('/api/v1/customer/auth/verify', ['phone' => '9876500011', 'code' => $code])->assertOk()->json('data');
        $this->assertCount(2, $choice['choose']);
        $this->assertSame(['c' . $own->id, 'd' . $this->ramesh->id], array_column($choice['choose'], 'key'));

        $this->postJson('/api/v1/customer/auth/choose', ['ticket' => 'nonsense', 'key' => 'd' . $this->ramesh->id])->assertStatus(422);
        $token = $this->postJson('/api/v1/customer/auth/choose', ['ticket' => $choice['ticket'], 'key' => 'd' . $this->ramesh->id])
            ->assertOk()->assertJsonPath('data.viewer.role', 'driver')->json('data.token');
        $this->app($token)->getJson('/api/v1/customer/home')->assertOk()->assertJsonPath('data.vehicles.0.registration_no', 'MH12CB0001');
    }

    public function test_driver_approves_up_to_the_limit_and_the_rest_goes_to_the_owner(): void
    {
        $job = $this->newJob($this->cab1, $this->ramesh->id);
        [$a, $b, $c] = $this->estimate($job, [1200, 700, 1500]);

        $token = $this->signInDriver($this->ramesh);
        $page = $this->app($token)->getJson("/api/v1/customer/jobs/{$job->id}")->assertOk()->json('data');
        $this->assertEquals(2000, $page['job']['driver_limit_left']);
        $this->assertNotSame(app(CustomerLinkService::class)->ensure($job)->token, $page['page_token']);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/j/{$page['page_token']}")->assertOk()
            ->assertJsonPath('data.fleet.driver', 'Ramesh Jadhav')->assertJsonPath('data.fleet.remaining', 2000);

        // 1200 + 700 fit in 2000; 1500 does not
        $res = $this->decide($page['page_token'], [$a => 'approved', $b => 'approved', $c => 'approved'])->assertOk();
        $this->assertCount(1, $res->json('data.fleet_result.held'));
        $this->assertEquals(100, $res->json('data.fleet_result.remaining'));

        $lines = JobCardEstimateLine::whereIn('line_key', [$a, $b, $c])->get()->keyBy('line_key');
        $this->assertSame('approved', $lines[$a]->approval_status);
        $this->assertSame($this->ramesh->id, $lines[$a]->decided_by_driver_id);
        $this->assertSame('fleet_driver', $lines[$a]->decided_via);
        $this->assertSame('pending', $lines[$c]->approval_status);

        // The owner is asked in the app and on WhatsApp; the advisor gets a bell
        $this->assertSame(1, CustomerNotification::where('customer_id', $this->fleet->id)->where('recipient_key', 0)->where('kind', 'approval')->where('title', 'like', 'Ramesh asks you%')->count());
        $this->assertSame(1, CustomerMessage::where('job_card_id', $job->id)->where('template', 'fleet_owner_approval')->count());
        $this->assertSame(1, $this->advisor->notifications()->where('data->kind', 'fleet_owner_asked')->count());

        // Declining frees its share of the limit: 700 used, so 1300 is left (still short of 1500)
        $this->decide($page['page_token'], [$a => 'declined'])->assertOk();
        $res = $this->decide($page['page_token'], [$c => 'approved'])->assertOk();
        $this->assertCount(1, $res->json('data.fleet_result.held'));
        $this->assertEquals(1300, $res->json('data.fleet_result.remaining'));

        // The owner approves it on their own link: no limit, and the driver can't undo the owner's answer
        $this->decide(app(CustomerLinkService::class)->ensure($job)->token, [$c => 'approved'])->assertOk();
        $this->assertSame('approved', JobCardEstimateLine::where('line_key', $c)->value('approval_status'));
        $res = $this->decide($page['page_token'], [$c => 'declined'])->assertOk();
        $this->assertSame(['Item 2'], $res->json('data.fleet_result.owner_decided'));
        $this->assertSame('approved', JobCardEstimateLine::where('line_key', $c)->value('approval_status'));
    }

    public function test_fleet_limit_overrides_the_master_default(): void
    {
        $this->staff($this->advisor)->putJson("/api/v1/fleet/customers/{$this->fleet->id}", ['fleet_driver_limit' => 5000])
            ->assertOk()->assertJsonPath('data.limit', 5000)->assertJsonPath('data.default_limit', 2000);

        $job = $this->newJob($this->cab1, $this->ramesh->id);
        [$a, $b] = $this->estimate($job, [3000, 1800]);
        $link = app(CustomerLinkService::class)->ensure($job, null, $this->ramesh->id)->token;

        $res = $this->decide($link, [$a => 'approved', $b => 'approved'])->assertOk();
        $this->assertNull($res->json('data.fleet_result'));
        $this->assertSame(2, JobCardEstimateLine::whereIn('line_key', [$a, $b])->where('approval_status', 'approved')->count());

        // Back to the default
        $this->staff($this->advisor)->putJson("/api/v1/fleet/customers/{$this->fleet->id}", ['fleet_driver_limit' => null])->assertOk()->assertJsonPath('data.limit', 2000);
    }

    public function test_drivers_link_signs_in_the_driver(): void
    {
        $job = $this->newJob($this->cab1, $this->ramesh->id);
        $link = app(CustomerLinkService::class)->ensure($job, null, $this->ramesh->id)->token;

        $this->postJson('/api/v1/customer/auth/link', ['kind' => 'job', 'token' => $link])->assertOk()
            ->assertJsonPath('data.viewer.role', 'driver')->assertJsonPath('data.job_id', $job->id);
    }

    public function test_owner_manages_drivers_and_sees_the_dashboard(): void
    {
        $job = $this->newJob($this->cab1, $this->ramesh->id);
        $this->estimate($job, [900]);
        $token = $this->signInOwner($job);

        $this->app($token)->getJson('/api/v1/customer/home')->assertOk()->assertJsonPath('data.viewer.role', 'fleet_owner');

        $id = $this->app($token)->postJson('/api/v1/customer/fleet/drivers', ['name' => 'Anil More', 'phone' => '9811122233', 'vehicle_ids' => [$this->cab2->id]])
            ->assertOk()->json('data.id');
        $this->assertSame('owner_app', FleetDriver::find($id)->created_via);
        // Same number twice on the fleet, or someone else's cab
        $this->app($token)->postJson('/api/v1/customer/fleet/drivers', ['name' => 'Anil 2', 'phone' => '+91 98111 22233'])->assertStatus(422);
        $other = Vehicle::create(['customer_id' => Customer::create(['name' => 'X', 'phone' => '9000000001'])->id, 'registration_no' => 'MH01XX0001']);
        $this->app($token)->putJson("/api/v1/customer/fleet/drivers/{$id}", ['vehicle_ids' => [$other->id]])->assertStatus(422);

        $dash = $this->app($token)->getJson('/api/v1/customer/fleet')->assertOk()->json('data');
        $this->assertSame(2, $dash['totals']['cabs']);
        $this->assertSame(1, $dash['totals']['at_workshop']);
        $this->assertSame(1, $dash['totals']['approvals_waiting']);
        $cab1 = collect($dash['cabs'])->firstWhere('id', $this->cab1->id);
        $this->assertSame('workshop', $cab1['state']);
        $this->assertSame('Ramesh Jadhav', $cab1['job']['driver']);
        $this->assertSame(58000, $cab1['km']);
        $this->assertSame(2000, $cab1['km_to_service']);
    }

    public function test_problem_report_is_booked_and_then_moves_to_the_job_card(): void
    {
        $token = $this->signInDriver($this->ramesh);

        // Not his cab
        $this->app($token)->postJson('/api/v1/customer/reports', ['vehicle_id' => $this->cab2->id, 'kind' => 'noise', 'description' => 'Rattle'])->assertNotFound();

        $id = $this->app($token)->post('/api/v1/customer/reports', [
            'vehicle_id' => $this->cab1->id,
            'kind' => 'breakdown',
            'description' => 'Engine stopped on the highway',
            'odometer_km' => 59000,
            'lat' => 18.52, 'lng' => 73.85,
            'media' => [UploadedFile::fake()->image('engine.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()->json('data.id');

        $report = FleetReport::findOrFail($id);
        $this->assertSame($this->ramesh->id, $report->fleet_driver_id);
        $this->assertSame(1, $report->media()->count());
        $this->assertSame(59000, OdometerReading::latestFor($this->cab1->id)->km);
        // Staff with the permission get a bell; the owner hears in the app
        $this->assertSame(1, $this->advisor->notifications()->where('data->kind', 'fleet_report')->count());
        $this->assertSame(1, CustomerNotification::where('customer_id', $this->fleet->id)->where('recipient_key', 0)->where('title', 'like', 'Ramesh reported%')->count());

        $this->staff($this->advisor)->getJson('/api/v1/fleet/reports')->assertOk()->assertJsonPath('data.reports.0.id', $id);
        $date = today()->addDay()->toDateString();
        $slot = app(\App\Modules\Crm\Services\BookingService::class)->availability($report->branch_id, $date)->firstWhere(fn ($s) => $s['free'] > 0);
        $this->staff($this->advisor)->postJson("/api/v1/fleet/reports/{$id}/book", [
            'booking_date' => $date, 'slot_from' => $slot['from'] ?? null, 'service_mode' => 'drive_in',
        ])->assertOk()->assertJsonPath('data.status', 'booked');
        $booking = Booking::findOrFail($report->fresh()->booking_id);
        $this->assertSame('general_repair', $booking->service_type);
        $this->assertStringContainsString('Engine stopped', (string) $booking->notes);

        // The driver is told, and the job card picks the report up
        $this->assertTrue(CustomerNotification::where('recipient_key', $this->ramesh->id)->where('title', 'like', 'Booked in%')->exists());
        $job = $this->newJob($this->cab1, $this->ramesh->id);
        $this->assertSame('job_opened', $report->fresh()->status);
        $this->assertSame($job->id, $report->fresh()->job_card_id);
    }

    public function test_odometer_readings_and_km_reminders_once(): void
    {
        $token = $this->signInDriver($this->ramesh);
        $this->app($token)->postJson("/api/v1/customer/vehicles/{$this->cab1->id}/odometer", ['km' => 59700])->assertOk();
        // A big drop is refused
        $this->app($token)->postJson("/api/v1/customer/vehicles/{$this->cab1->id}/odometer", ['km' => 50000])->assertStatus(422);
        $this->app($token)->postJson("/api/v1/customer/vehicles/{$this->cab2->id}/odometer", ['km' => 1000])->assertNotFound();

        $this->artisan('fleet:daily')->assertSuccessful();
        $this->artisan('fleet:daily')->assertSuccessful();
        $this->assertSame(1, CustomerNotification::where('recipient_key', 0)->where('kind', 'service_due')->count());
        $this->assertSame(1, CustomerNotification::where('recipient_key', $this->ramesh->id)->where('kind', 'service_due')->count());

        // The driver's inbox has theirs only
        $this->app($token)->getJson('/api/v1/customer/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cab_ready_message_goes_to_the_driver(): void
    {
        $job = $this->newJob($this->cab1, $this->ramesh->id);
        \App\Modules\Workshop\Models\JobCardStatusHistory::create(['job_card_id' => $job->id, 'from_status' => 'final_qc', 'to_status' => 'ready_for_delivery', 'changed_by' => $this->advisor->id]);

        $message = CustomerMessage::where('job_card_id', $job->id)->where('template', 'fleet_driver_ready')->firstOrFail();
        $this->assertSame('919876500011', $message->phone);
        $this->assertStringContainsString('Hello Ramesh', $message->body);
    }

    public function test_fleet_module_switched_off(): void
    {
        $token = $this->signInDriver($this->ramesh);
        WorkshopSettings::set('modules.fleet', false);

        $this->app($token)->getJson('/api/v1/customer/home')->assertUnauthorized();
        $this->staff($this->advisor)->getJson('/api/v1/fleet/reports')->assertNotFound();
        $this->postJson('/api/v1/customer/auth/code', ['phone' => $this->ramesh->phone])->assertOk();
        $this->assertSame(0, \App\Modules\CustomerApp\Models\CustomerOtp::where('phone', '919876500011')->whereNull('used_at')->count());
    }
}
