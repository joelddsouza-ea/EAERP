<?php

namespace Tests\Feature;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\LabourRate;
use App\Modules\Workshop\Models\LabourService;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackageLineItem;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\JobSheetTemplatesSeeder;
use Database\Seeders\LabourChargesSeeder;
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
use Tests\Concerns\CompletesIntake;
use Tests\TestCase;

/**
 * Client changes before Phase 2 is committed: labour charges master, plan
 * management, the parts pricing loop with the spare parts coordinator, staff
 * notifications, "decline all", and the intake trial drive + inventory media.
 */
class PartsLabourIntakeTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private User $coordinator;
    private int $branchId;
    private Vehicle $vehicle;
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            OrganizationSeeder::class,
            RolesAndPermissionsSeeder::class,
            VehicleCategoriesSeeder::class,
            OilTypesSeeder::class,
            ServicePackagesSeeder::class,
            LabourChargesSeeder::class,
            TaxRatesSeeder::class,
            JobSheetTemplatesSeeder::class,
            AdminUserSeeder::class,
        ]);

        Storage::fake('job_media');

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->coordinator = User::where('email', 'parts@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Pranav Shroff', 'phone' => '98765 43210']);
        $this->vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH05DS4417',
            'category_id' => $this->category('sedan-compact-suv'),
            'fuel_type' => 'petrol',
        ]);
        $this->customerId = $customer->id;
    }

    // ---------------------------------------------------------------- helpers

    private function as(?User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function category(string $slug): int
    {
        return VehicleCategory::where('slug', $slug)->value('id');
    }

    private function base(JobCard $job): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}";
    }

    private function newJob(): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 12000,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicle->id,
            'package_id' => ServicePackage::where('slug', 'ea-360')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'service_type' => 'scheduled',
        ])->assertCreated()->json('data.id');

        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $job->update(['service_advisor_id' => $this->advisor->id]);

        return $job->fresh();
    }

    private function jobInProgress(): JobCard
    {
        $job = $this->newJob();
        $job->update(['km_reading' => 56518, 'fuel_level' => 'half', 'technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        // Intake complete + technician assigned: work starts by itself
        $this->assertSame('work_in_progress', $job->fresh()->status);

        return $job->fresh();
    }

    private function draft(JobCard $job): array
    {
        return $this->as($this->advisor)->postJson($this->base($job) . '/estimates')->assertOk()->json('data');
    }

    private function labourRate(string $service, string $categorySlug, string $fuel): ?float
    {
        $rate = LabourRate::where('labour_service_id', LabourService::where('name', $service)->value('id'))
            ->where('category_id', $this->category($categorySlug))
            ->where('fuel_group', $fuel)
            ->firstOrFail()->rate;

        return $rate === null ? null : (float) $rate;
    }

    /**
     * Advisor asks for a price, coordinator quotes it (₹1000 + GST) and submits.
     */
    private function quotedPart(JobCard $job, string $name = 'Brake Pad Set'): SparePartRequest
    {
        $this->as($this->advisor)->postJson($this->base($job) . '/part-requests', [
            'parts' => [['part_name' => $name, 'part_number' => 'BP-01', 'quantity' => 1]],
        ])->assertCreated();

        $request = SparePartRequest::where('job_card_id', $job->id)->where('part_name', $name)->firstOrFail();

        $quoteId = $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$request->id}/quotes", [
            'supplier_name' => 'Shree Auto Parts', 'unit_cost' => 1000, 'gst_mode' => 'excl',
        ])->assertCreated()->json('data.id');

        $this->as($this->coordinator)->postJson('/api/v1/workshop/parts-desk/submit', [
            'selections' => [$request->id => $quoteId],
        ])->assertOk();

        return $request->fresh();
    }

    // ------------------------------------------------------------------ tests

    public function test_labour_charges_match_the_owner_chart(): void
    {
        $this->assertSame(33, LabourService::count());
        $this->assertEquals(1475, $this->labourRate('Brake Service', 'sedan-compact-suv', 'petrol'));
        $this->assertEquals(118, $this->labourRate('Engine Tuning (Spark Plug Cleaning, Adjustment & Air Filter Cleaning)', 'hatchback', 'diesel'));
        $this->assertNull($this->labourRate('Clutch Overhaul', 'luxury-class-2', 'diesel'));
        $this->assertNull($this->labourRate('Throttle Body Servicing', 'hatchback', 'diesel'));
        // One column on the chart for SUVs: same rate for every fuel
        $this->assertEquals($this->labourRate('Timing Belt Replacement', 'suv-muv', 'diesel'), $this->labourRate('Timing Belt Replacement', 'suv-muv', 'petrol'));
        $this->assertSame('hour', LabourService::where('name', 'like', 'Other General Repairs%')->value('unit'));

        // An edit in the app survives re-seeding
        $service = LabourService::where('name', 'Brake Service')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/pricing-masters/labour-services/{$service->id}", [
            'rates' => ['petrol' => [$this->category('sedan-compact-suv') => 1600]],
        ])->assertOk();
        $this->seed(LabourChargesSeeder::class);
        $this->assertEquals(1600, $this->labourRate('Brake Service', 'sedan-compact-suv', 'petrol'));

        $this->as($this->advisor)->putJson("/api/v1/workshop/pricing-masters/labour-services/{$service->id}", ['name' => 'x'])->assertForbidden();
    }

    public function test_labour_rate_follows_the_cars_type_and_fuel(): void
    {
        $job = $this->jobInProgress();
        $tuning = 'Engine Tuning';

        $rate = fn () => collect($this->as($this->advisor)->getJson($this->base($job) . '/labour-charges?q=' . urlencode($tuning))
            ->assertOk()->json('data'))->first()['rate'];

        $this->assertEquals(472, $rate()); // petrol sedan

        $this->vehicle->update(['fuel_type' => 'diesel', 'category_id' => $this->category('hatchback')]);
        $this->assertEquals(118, $rate());

        $this->vehicle->update(['fuel_type' => 'cng']);
        $this->assertEquals(472, $rate()); // CNG uses the petrol column

        // Suggestions offer the master first and the line keeps the link
        $estimate = $this->draft($job);
        $suggestion = $this->as($this->advisor)->getJson("/api/v1/workshop/estimate-suggestions?q=Brake%20Service&job_card_id={$job->id}")
            ->assertOk()->json('data.0');
        $this->assertSame('labour', $suggestion['source']);
        $this->assertEquals(1180, $suggestion['last_rate']);

        $data = $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/lines", [
            'kind' => 'labor', 'description' => $suggestion['description'], 'rate' => $suggestion['last_rate'],
            'labour_service_id' => $suggestion['labour_service_id'],
        ])->assertCreated()->json('data');
        $this->assertSame($suggestion['labour_service_id'], collect($data['additional_lines'])->last()['labour_service_id']);
    }

    public function test_owner_manages_service_plans(): void
    {
        $url = '/api/v1/workshop/pricing-masters/packages';

        $this->as($this->advisor)->postJson($url, ['name' => 'EA Monsoon'])->assertForbidden();

        $plan = $this->as($this->owner)->postJson($url, [
            'name' => 'EA Monsoon',
            'description' => 'Monsoon check-up',
            'features' => ['Wiper check', '  ', 'Underbody wash'],
            'activity_points' => 12,
        ])->assertCreated()->json('data');
        $this->assertSame('ea-monsoon', $plan['slug']);
        $this->assertSame(['Wiper check', 'Underbody wash'], $plan['features']);

        $this->as($this->owner)->putJson("{$url}/{$plan['id']}", ['name' => 'EA Monsoon Care', 'home_service_available' => true])
            ->assertOk()->assertJsonPath('data.slug', 'ea-monsoon')->assertJsonPath('data.name', 'EA Monsoon Care');

        $listed = fn () => collect($this->as($this->advisor)->getJson('/api/v1/workshop/service-packages')->json('data'))->pluck('slug');
        $this->assertContains('ea-monsoon', $listed());

        $this->as($this->owner)->putJson("{$url}/{$plan['id']}", ['is_active' => false])->assertOk();
        $this->assertNotContains('ea-monsoon', $listed());

        // Plan items can be deleted
        $item = PackageLineItem::firstOrFail();
        $this->as($this->owner)->deleteJson("/api/v1/workshop/pricing-masters/line-items/{$item->id}")->assertOk();
        $this->assertNull(PackageLineItem::find($item->id));
    }

    public function test_parts_price_loop_with_margin_and_procurement(): void
    {
        $job = $this->jobInProgress();
        $estimate = $this->draft($job);

        $this->as($this->advisor)->postJson($this->base($job) . '/part-requests', [
            'parts' => [['part_name' => 'Brake Pad Set', 'part_number' => 'BP-01', 'quantity' => 1, 'note' => 'Front']],
        ])->assertCreated();
        $this->assertSame('parts_price_requested', $this->coordinator->notifications()->first()->data['kind']);

        $request = SparePartRequest::where('job_card_id', $job->id)->firstOrFail();

        // Advisors can't quote; not on an estimate until priced
        $this->as($this->tech)->postJson("/api/v1/workshop/parts-desk/requests/{$request->id}/quotes", [
            'supplier_name' => 'X', 'unit_cost' => 1, 'gst_mode' => 'incl',
        ])->assertForbidden();
        $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/parts", ['spare_part_request_id' => $request->id])
            ->assertStatus(422);

        $desk = '/api/v1/workshop/parts-desk';
        $excl = $this->as($this->coordinator)->postJson("{$desk}/requests/{$request->id}/quotes", [
            'supplier_name' => 'Shree Auto Parts', 'unit_cost' => 1000, 'gst_mode' => 'excl', 'availability' => 'Today 4 pm',
        ])->assertCreated()->assertJsonPath('data.landed_unit_cost', '1180.00')->json('data');
        $this->as($this->coordinator)->postJson("{$desk}/requests/{$request->id}/quotes", [
            'supplier_name' => 'Cash Counter', 'unit_cost' => 1250, 'gst_mode' => 'cash',
        ])->assertCreated()->assertJsonPath('data.landed_unit_cost', '1250.00');

        $this->assertCount(1, $this->as($this->coordinator)->getJson($desk)->assertOk()->json('data.requests'));

        $this->as($this->coordinator)->postJson("{$desk}/submit", ['selections' => [$request->id => $excl['id']]])->assertOk();
        $this->assertSame(SparePartRequest::QUOTED, $request->fresh()->status);
        $this->assertSame('parts_quoted', $this->advisor->notifications()->first()->data['kind']);

        // Default margin 20% on the GST-inclusive cost
        $data = $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/parts", ['spare_part_request_id' => $request->id])
            ->assertCreated()->json('data');
        $line = collect($data['additional_lines'])->firstWhere('spare_part_request_id', $request->id);
        $this->assertEquals(1416, $line['rate']);
        $this->assertEquals(1180, $line['unit_cost']);
        $this->assertEquals(20, $line['margin_pct']);

        // Changing the margin reprices the line; typing a rate recalculates the margin
        $lineUrl = $this->base($job) . "/estimates/{$estimate['id']}/lines/{$line['id']}";
        $this->as($this->advisor)->putJson($lineUrl, ['margin_pct' => 10])->assertOk();
        $this->assertEquals(1298, JobCardEstimateLine::find($line['id'])->rate);
        $this->as($this->advisor)->putJson($lineUrl, ['rate' => 1475])->assertOk();
        $this->assertEquals(25, JobCardEstimateLine::find($line['id'])->margin_pct);

        // The customer never sees cost or margin
        $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/send")->assertOk();
        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $public = $this->getJson("/api/v1/public/j/{$token}")->assertOk();
        $this->assertStringNotContainsString('unit_cost', $public->getContent());
        $this->assertStringNotContainsString('margin', $public->getContent());
        $this->assertStringNotContainsString('1180', $public->getContent());

        // Approval → coordinator told to procure → ordered
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => [['line_key' => $line['line_key'], 'decision' => 'approved']],
        ])->assertOk();
        $this->assertSame(SparePartRequest::APPROVED, $request->fresh()->status);
        $kinds = fn (User $u) => $u->notifications()->get()->pluck('data.kind')->all();
        $this->assertContains('parts_to_procure', $kinds($this->coordinator));
        $this->assertContains('estimate_answered', $kinds($this->advisor));

        $procure = $this->as($this->coordinator)->getJson("{$desk}?tab=procure")->assertOk()->json('data.requests');
        $this->assertSame('Shree Auto Parts', collect($procure[0]['quotes'])->firstWhere('id', $procure[0]['selected_quote_id'])['supplier']);

        $this->as($this->coordinator)->postJson("{$desk}/requests/{$request->id}/ordered")->assertOk();
        $this->assertSame(SparePartRequest::ORDERED, $request->fresh()->status);

        // Notifications API
        $bell = $this->as($this->coordinator)->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertSame(2, $bell['unread']);
        $this->as($this->coordinator)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->as($this->coordinator)->getJson('/api/v1/notifications')->json('data.unread'));
    }

    public function test_customer_can_decline_everything_and_parts_follow(): void
    {
        $job = $this->jobInProgress();
        $request = $this->quotedPart($job);
        $estimate = $this->draft($job);
        $data = $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/parts", ['spare_part_request_id' => $request->id])
            ->assertCreated()->json('data');
        $data = $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/lines", [
            'kind' => 'labor', 'description' => 'Brake Pads Set Replacement', 'rate' => 944,
        ])->assertCreated()->json('data');
        $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/send")->assertOk();

        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        // "Decline all" sends a decline for every additional line
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => collect($data['additional_lines'])->map(fn ($l) => ['line_key' => $l['line_key'], 'decision' => 'declined'])->all(),
        ])->assertOk();

        $this->assertSame(SparePartRequest::DECLINED, $request->fresh()->status);
        $this->assertSame('work_in_progress', $job->fresh()->status);
        $this->assertSame(0, $job->items()->count());
    }

    public function test_intake_needs_trial_drive_and_inventory_media(): void
    {
        $job = $this->newJob();
        $job->update(['km_reading' => 1200, 'fuel_level' => 'half', 'technician_id' => $this->tech->id]);
        $this->assertSame('intake_pending', $job->fresh()->status);

        // Skipping the trial drive needs a reason
        $this->as($this->advisor)->putJson($this->base($job) . '/intake/trial', ['status' => 'skipped'])->assertStatus(422);
        $this->as($this->advisor)->putJson($this->base($job) . '/intake/trial', ['status' => 'skipped', 'notes' => 'Battery dead, car towed in'])
            ->assertOk()->assertJsonPath('data.trial.status', 'skipped');
        // The first intake action starts intake by itself
        $this->assertSame('intake_in_progress', $job->fresh()->status);

        $move = fn () => $this->as($this->owner)->patchJson($this->base($job) . '/status', ['status' => 'work_in_progress', 'notes' => 'Checking']);
        $guard = collect($move()->assertStatus(422)->json('errors.guards'))->firstWhere('code', 'intake_incomplete');
        $this->assertSame([], $guard['details']['fields']);
        $this->assertSame(4, $guard['details']['photos_needed']);

        // Owner lowers the video minimum; four photos complete the intake
        $this->as($this->advisor)->putJson('/api/v1/workshop/intake-requirements', ['min_photos' => 4, 'min_videos' => 0])->assertForbidden();
        $this->as($this->owner)->putJson('/api/v1/workshop/intake-requirements', ['min_photos' => 4, 'min_videos' => 0])->assertOk();

        $mediaId = null;
        foreach (range(1, 3) as $i) {
            $mediaId = $this->as($this->advisor)->post($this->base($job) . '/intake/media', ['file' => UploadedFile::fake()->image("side{$i}.jpg", 400, 300)])
                ->assertCreated()->json('data.media.id');
        }
        $this->as($this->advisor)->getJson($this->base($job) . '/intake')->assertOk()->assertJsonPath('data.counts.photo', 3);

        $this->as($this->advisor)->deleteJson($this->base($job) . "/intake/media/{$mediaId}")->assertOk();
        $move()->assertStatus(422);
        $this->assertSame('intake_in_progress', $job->fresh()->status);

        // The fourth photo completes intake: with a technician assigned, work starts by itself
        foreach (['rear', 'front'] as $side) {
            $this->as($this->advisor)->post($this->base($job) . '/intake/media', ['file' => UploadedFile::fake()->image("{$side}.jpg", 400, 300)])->assertCreated();
        }
        $this->assertSame('work_in_progress', $job->fresh()->status);

        // Intake is frozen once work starts
        $this->as($this->advisor)->putJson($this->base($job) . '/intake/trial', ['status' => 'done'])->assertStatus(422);
    }

    public function test_jobs_already_at_work_are_not_held_back_by_the_intake_rules(): void
    {
        // A job that started work before these rules, now waiting on an estimate
        $job = $this->newJob();
        $job->update([
            'status' => 'awaiting_approval',
            'km_reading' => 1200,
            'fuel_level' => 'half',
            'technician_id' => $this->tech->id,
            'work_started_at' => now()->subDay(),
        ]);

        app(\App\Modules\Workshop\Services\JobLifecycleService::class)->sync($job->fresh());

        $this->assertSame('work_in_progress', $job->fresh()->status);
    }
}
