<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\OilType;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Create / Edit Job Card: the service type is a plan or one repair type; a plan needs
 * an engine oil grade it is priced for; price and job sheets follow the choice.
 */
class JobCardFormTest extends TestCase
{
    use RefreshDatabase;

    private User $advisor;
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
            TaxRatesSeeder::class,
            JobSheetTemplatesSeeder::class,
            AdminUserSeeder::class,
        ]);

        $this->advisor = User::where('email', 'advisor@expressautocare.com')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Form Test', 'phone' => '9000000088']);
        $this->customerId = $customer->id;
        $this->vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH02FORM',
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ]);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function plan(string $slug): int
    {
        return ServicePackage::where('slug', $slug)->value('id');
    }

    private function oil(string $slug): int
    {
        return OilType::where('slug', $slug)->value('id');
    }

    private function create(array $data)
    {
        return $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicle->id,
            ...$data,
        ]);
    }

    private function sheetNames(int $jobId): array
    {
        return JobCard::withoutGlobalScopes()->findOrFail($jobId)->checklists()->pluck('name')
            ->map(fn ($n) => preg_replace('/\s+—\s+.*$/u', '', $n))->all();
    }

    public function test_options_list_plans_with_priced_oil_grades_and_job_sheets(): void
    {
        $data = $this->as($this->advisor)->getJson("/api/v1/workshop/job-card-options?vehicle_id={$this->vehicle->id}")
            ->assertOk()->json('data');

        $this->assertSame('Prem. Hatch / Sedan / Compact SUV', $data['car_type']['name']);
        $this->assertSame(['EA Refresh', 'EA Essentials', 'EA Pro', 'EA 360', 'EA Elite'], array_column($data['plans'], 'name'));

        $ea360 = collect($data['plans'])->firstWhere('name', 'EA 360');
        $this->assertEquals(9575, collect($ea360['oil_options'])->firstWhere('name', 'Fully-Synthetic')['price']);
        $this->assertCount(2, $ea360['oil_options']); // the sheet offers EA 360 on this car type with semi- and fully-synthetic only
        $this->assertEqualsCanonicalizing(['360 Service (70 Points)', 'Additional Job / Observation Report', 'Pre-Delivery Checklist'], $ea360['sheets']);

        $ac = collect($data['service_types'])->firstWhere('value', 'ac_service');
        $this->assertTrue(collect($ac['sheets'])->contains(fn ($s) => str_contains($s, 'AC')));
        $this->assertNotContains('scheduled', array_column($data['service_types'], 'value'));
        $this->assertNull($data['lock']);
    }

    public function test_a_plan_needs_an_oil_grade_it_is_priced_for(): void
    {
        $this->create(['service_type' => 'scheduled', 'package_id' => $this->plan('ea-360')])
            ->assertStatus(422)->assertJsonValidationErrors('oil_type_id');

        $this->create(['service_type' => 'scheduled'])
            ->assertStatus(422)->assertJsonValidationErrors('package_id');

        // Remove one price: that grade is no longer offered for this car type
        $sedan = $this->vehicle->category_id;
        \App\Modules\Workshop\Models\PackagePricing::where([
            'package_id' => $this->plan('ea-360'), 'category_id' => $sedan, 'oil_type_id' => $this->oil('semi-synthetic'),
        ])->delete();
        $this->create(['service_type' => 'scheduled', 'package_id' => $this->plan('ea-360'), 'oil_type_id' => $this->oil('semi-synthetic')])
            ->assertStatus(422)->assertJsonValidationErrors('oil_type_id');
    }

    public function test_plan_job_is_priced_and_gets_the_plan_sheets(): void
    {
        $id = $this->create([
            'service_type' => 'scheduled',
            'package_id' => $this->plan('ea-360'),
            'oil_type_id' => $this->oil('fully-synthetic'),
            'priority' => 'urgent',
        ])->assertCreated()->json('data.id');

        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $this->assertEquals(9575, $job->package_price);
        $this->assertSame('normal', $job->priority ?? 'normal');
        $this->assertTrue(collect($this->sheetNames($id))->contains(fn ($n) => str_contains($n, '360 Service')));
        $this->assertTrue(collect($this->sheetNames($id))->contains(fn ($n) => str_contains($n, 'Observation')));
    }

    public function test_a_repair_type_has_no_plan_and_oil_is_optional(): void
    {
        $id = $this->create([
            'service_type' => 'ac_service',
            'package_id' => $this->plan('ea-360'), // ignored: one choice per job card
        ])->assertCreated()->json('data.id');

        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $this->assertNull($job->package_id);
        $this->assertTrue(collect($this->sheetNames($id))->contains(fn ($n) => str_contains($n, 'AC')));
        $this->assertFalse(collect($this->sheetNames($id))->contains(fn ($n) => str_contains($n, '360 Service')));
    }

    public function test_choosing_a_plan_on_an_older_job_without_one_adds_the_plan_sheet(): void
    {
        // Created by the earlier form: "scheduled" with no plan, so only the pre-delivery sheet
        $job = app(\App\Modules\Workshop\Services\JobCardService::class)->createJobCard([
            'branch_id' => $this->branchId,
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicle->id,
            'service_type' => 'scheduled',
        ]);
        $this->assertFalse(collect($this->sheetNames($job->id))->contains(fn ($n) => str_contains($n, 'REFRESH')));

        $this->as($this->advisor)->putJson("/api/v1/workshop/job-cards/{$job->id}", [
            'service_type' => 'scheduled',
            'package_id' => $this->plan('ea-refresh'),
            'oil_type_id' => $this->oil('fully-synthetic'),
        ])->assertOk();

        $job->refresh();
        $this->assertSame($this->plan('ea-refresh'), $job->package_id);
        $this->assertSame($this->oil('fully-synthetic'), $job->oil_type_id);
        $this->assertTrue(collect($this->sheetNames($job->id))->contains(fn ($n) => str_contains($n, 'REFRESH')));
    }

    public function test_saving_a_job_adds_plan_sheets_it_is_missing(): void
    {
        // Plan set behind the job card's back (as happened on dev jobs), so its sheet never came
        $job = app(\App\Modules\Workshop\Services\JobCardService::class)->createJobCard([
            'branch_id' => $this->branchId,
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicle->id,
            'service_type' => 'ac_service',
        ]);
        JobCard::withoutGlobalScopes()->whereKey($job->id)->update([
            'service_type' => 'scheduled',
            'package_id' => $this->plan('ea-refresh'),
            'oil_type_id' => $this->oil('fully-synthetic'),
        ]);

        // Any save from the Edit screen, even without touching the plan
        $this->as($this->advisor)->putJson("/api/v1/workshop/job-cards/{$job->id}", ['customer_complaints' => 'Brake noise'])
            ->assertOk();

        $this->assertTrue(collect($this->sheetNames($job->id))->contains(fn ($n) => str_contains($n, 'REFRESH')));
    }

    public function test_changing_plan_swaps_sheets_and_draft_until_an_estimate_is_sent(): void
    {
        $id = $this->create([
            'service_type' => 'scheduled',
            'package_id' => $this->plan('ea-360'),
            'oil_type_id' => $this->oil('fully-synthetic'),
        ])->assertCreated()->json('data.id');
        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $url = "/api/v1/workshop/job-cards/{$id}";

        $this->as($this->advisor)->postJson("{$url}/estimates")->assertOk();

        $this->as($this->advisor)->putJson($url, ['service_type' => 'scheduled', 'package_id' => $this->plan('ea-pro')])->assertOk();
        $job->refresh();
        $this->assertSame($this->plan('ea-pro'), $job->package_id);
        $this->assertTrue(collect($this->sheetNames($id))->contains(fn ($n) => str_contains($n, 'PRO Service')));
        $this->assertFalse(collect($this->sheetNames($id))->contains(fn ($n) => str_contains($n, '360 Service')));
        $draft = JobCardEstimate::where('job_card_id', $id)->where('status', 'draft')->firstOrFail();
        $this->assertSame($this->plan('ea-pro'), $draft->package_id);

        // Once the customer has the estimate, the plan is locked on the job card
        $job->update(['status' => 'work_in_progress', 'km_reading' => 100, 'fuel_level' => 'half']);
        $this->as($this->advisor)->postJson("{$url}/estimates/{$draft->id}/send")->assertOk();

        $this->as($this->advisor)->putJson($url, ['package_id' => $this->plan('ea-360')])->assertStatus(422);
        $this->assertNotNull(
            $this->as($this->advisor)->getJson("/api/v1/workshop/job-card-options?vehicle_id={$this->vehicle->id}&job_card_id={$id}")
                ->json('data.lock')
        );
        // Other fields still save
        $this->as($this->advisor)->putJson($url, ['customer_complaints' => 'Noise from front left'])->assertOk();
    }
}
