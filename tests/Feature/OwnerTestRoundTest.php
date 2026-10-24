<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Crm\Models\BookingSlot;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CompletesIntake;
use Tests\TestCase;

/**
 * The owner's test round (2026-10-24): roles & dashboards, car classes, weekly off in the
 * booking calendar, mobile numbers, owner-driven fleet cabs, diesel job sheets, Additional
 * Issues sections, activities done via their issue line.
 */
class OwnerTestRoundTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private int $branchId;

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
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function job(string $fuel = 'petrol', string $plan = 'ea-pro'): JobCard
    {
        $customer = Customer::create(['name' => 'Round ' . $fuel, 'phone' => '98' . random_int(10000000, 99999999)]);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH12' . strtoupper($fuel), 'category_id' => VehicleCategory::firstOrFail()->id, 'fuel_type' => $fuel]);
        $packageId = ServicePackage::where('slug', $plan)->value('id');
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 30000, 'fuel_level' => 'half', 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'package_id' => $packageId, 'service_type' => 'scheduled',
            'oil_type_id' => PackagePricing::where('package_id', $packageId)->where('category_id', $vehicle->category_id)->value('oil_type_id'),
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_owner_edits_role_access_and_dashboards(): void
    {
        $advisorRole = Role::where('slug', Role::SERVICE_ADVISOR)->firstOrFail();
        $ownerRole = Role::where('slug', Role::OWNER)->firstOrFail();

        // Access: the owner edits a role; the owner role itself can't be cut down
        $this->as($this->advisor)->postJson("/api/v1/user-admin/roles/{$advisorRole->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->as($this->owner)->postJson("/api/v1/user-admin/roles/{$advisorRole->id}/permissions", ['permissions' => ['workshop.job-cards.view']])->assertOk();
        $this->assertSame(['workshop.job-cards.view'], $advisorRole->fresh()->permissions()->pluck('slug')->all());
        $this->as($this->owner)->postJson("/api/v1/user-admin/roles/{$ownerRole->id}/permissions", ['permissions' => []])->assertStatus(422);

        // Dashboard: advisors don't see the money by default; the owner can change that
        $this->assertNotContains('todays_collection', $this->advisor->fresh()->dashboardBlocks());
        $this->assertContains('total_outstanding', $this->owner->dashboardBlocks());
        $this->as($this->owner)->putJson("/api/v1/user-admin/roles/{$advisorRole->id}/dashboard", ['blocks' => ['jobs_in_progress', 'todays_collection']])->assertOk();
        $this->as($this->advisor)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.dashboard_blocks', ['jobs_in_progress', 'todays_collection']);
        // All roles listed (more than one page of 15)
        $this->assertGreaterThanOrEqual(12, count($this->as($this->owner)->getJson('/api/v1/user-admin/roles')->json('data')));

        // The money figures need a billing permission
        $this->as($this->tech)->getJson('/api/v1/billing/dashboard')->assertForbidden();
    }

    public function test_premium_hatchback_is_gone(): void
    {
        $this->assertFalse(VehicleCategory::where('slug', 'premium-hatchback')->exists());
        $names = collect($this->as($this->advisor)->getJson('/api/v1/crm/lead-options')->json('data.categories'))->pluck('name');
        $this->assertNotContains('Premium Hatchback', $names);
    }

    public function test_booking_calendar_follows_the_branch_weekly_off(): void
    {
        Branch::whereKey($this->branchId)->update(['weekly_off_day' => Carbon::FRIDAY]);
        $service = app(\App\Modules\Crm\Services\BookingService::class);
        $sunday = Carbon::now()->next(Carbon::SUNDAY)->toDateString();
        $friday = Carbon::now()->next(Carbon::FRIDAY)->toDateString();

        $this->assertNotEmpty($service->availability($this->branchId, $sunday));
        $this->assertEmpty($service->availability($this->branchId, $friday));
        $this->assertFalse(BookingSlot::where('weekday', 0)->exists()); // Sunday runs on Monday's slots
    }

    public function test_mobile_numbers_are_ten_digits_and_registrations_capital(): void
    {
        $url = '/api/v1/workshop/customers';
        $this->as($this->advisor)->postJson($url, ['name' => 'Short', 'phone' => '98200123'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $id = $this->as($this->advisor)->postJson($url, ['name' => 'Plus', 'phone' => '+91 98200 12345'])->assertCreated()->json('data.id');
        $this->assertSame('9820012345', Customer::find($id)->phone);
        $this->as($this->advisor)->postJson('/api/v1/crm/leads', ['phone' => '12345', 'name' => 'Bad lead'])->assertStatus(422);

        $make = \App\Modules\Workshop\Models\VehicleMake::create(['name' => 'Maruti Suzuki', 'is_active' => true]);
        $model = \App\Modules\Workshop\Models\VehicleModel::create(['make_id' => $make->id, 'name' => 'Swift', 'is_active' => true]);
        $vehicleId = $this->as($this->advisor)->postJson('/api/v1/workshop/vehicles', [
            'customer_id' => $id, 'registration_no' => 'mh 12  ab 1234',
            'make_id' => $make->id,
            'model_id' => $model->id,
            'fuel_type' => 'petrol',
        ])->assertCreated()->json('data.id');
        $this->assertSame('MH 12 AB 1234', Vehicle::findOrFail($vehicleId)->registration_no);
    }

    public function test_diesel_car_has_spark_plug_points_na_and_locked(): void
    {
        $job = $this->job('diesel');
        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        $plugs = $sheet->items()->where('item_name', 'like', '%spark plug%')->get();
        $this->assertNotEmpty($plugs);
        $this->assertTrue($plugs->every(fn ($i) => $i->locked && $i->status === 'not_applicable'));

        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $url = "/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$sheet->id}/items/{$plugs[0]->id}";
        $this->as($this->tech)->patchJson($url, ['status' => 'pending'])->assertStatus(422);

        // "If applicable" points are marked for the colour; petrol cars keep the plug points
        $this->assertTrue($sheet->items()->where('item_name', 'like', '%if applicable%')->value('conditional'));
        $petrol = JobCardChecklist::where('job_card_id', $this->job('petrol')->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        $this->assertFalse((bool) $petrol->items()->where('item_name', 'like', '%spark plug%')->value('locked'));
    }

    public function test_trial_drive_notes_and_duplicate_lines_on_additional_issues(): void
    {
        $job = $this->job();
        $this->as($this->advisor)->putJson("/api/v1/workshop/job-cards/{$job->id}/intake/trial", ['status' => 'done', 'notes' => 'Pulls to the left'])->assertOk();
        $trial = JobCardIssue::where('job_card_id', $job->id)->where('source', 'trial')->sole();
        $this->assertSame('Pulls to the left', $trial->description);

        // The technician reports the same thing again, with a photo, and removes his duplicate
        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $line = $this->as($this->tech)->post("/api/v1/workshop/job-cards/{$job->id}/issues", [
            'description' => 'Car pulls left', 'files' => [UploadedFile::fake()->image('p.jpg', 200, 150)],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->as($this->tech)->deleteJson("/api/v1/workshop/job-cards/{$job->id}/issues/{$line}")->assertOk();
        $this->as($this->tech)->deleteJson("/api/v1/workshop/job-cards/{$job->id}/issues/{$trial->id}")->assertForbidden();
    }

    public function test_activity_handled_on_its_issue_line_asks_for_no_more_photos(): void
    {
        $job = $this->job();
        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        $item = $sheet->items()->where('media_requirement', '!=', 'none')->firstOrFail();

        $url = "/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$sheet->id}/items/{$item->id}";
        $this->as($this->tech)->post("{$url}/media", ['file' => UploadedFile::fake()->image('f.jpg', 200, 150)], ['Accept' => 'application/json'])->assertCreated();
        $this->as($this->tech)->patchJson($url, ['needs_attention' => true, 'remarks' => 'Worn out'])->assertOk();
        JobCardIssue::where('source_item_id', $item->id)->sole()->update(['status' => 'resolved']);

        $data = $this->as($this->tech)->getJson("/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$sheet->id}")->assertOk()->json('data');
        $row = collect($data['sections'])->flatMap(fn ($s) => $s['items'])->firstWhere('id', $item->id);
        $this->assertTrue($row['handled_by_line']);
        $this->assertNull($row['media_shortfall']);
    }

    public function test_owner_drives_some_fleet_cabs(): void
    {
        $fleet = Customer::create(['name' => 'Sunil Patil', 'phone' => '9820055500', 'customer_type' => 'fleet']);
        $cab = Vehicle::create(['customer_id' => $fleet->id, 'registration_no' => 'MH12CB0009', 'category_id' => VehicleCategory::firstOrFail()->id, 'fuel_type' => 'cng']);
        $this->as($this->advisor)->putJson("/api/v1/fleet/customers/{$fleet->id}", ['owner_cab_ids' => [$cab->id, 999999]])
            ->assertOk()->assertJsonPath('data.owner_cab_ids', [$cab->id]);
        $this->as($this->advisor)->getJson("/api/v1/fleet/customers/{$fleet->id}/drivers?vehicle_id={$cab->id}")
            ->assertOk()->assertJsonPath('data.owner_drives', true);
    }
}
