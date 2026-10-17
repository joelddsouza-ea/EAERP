<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateDecision;
use App\Modules\Workshop\Models\JobCardItem;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackageLineItem;
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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CompletesIntake;
use Tests\TestCase;

/**
 * Price masters, versioned estimates, customer approval by link, decisions on the
 * customer's behalf, WhatsApp messages and the service report (Phase 2).
 */
class EstimateApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private int $branchId;
    private int $vehicleId;
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

        Storage::fake('job_media');

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        // The sample estimate: Hyundai Xcent, Prem. Hatch / Sedan / Compact SUV
        $customer = Customer::create(['name' => 'Pranav Shroff', 'phone' => '98765 43210']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH05DS4417',
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ]);
        $this->customerId = $customer->id;
        $this->vehicleId = $vehicle->id;
    }

    // ---------------------------------------------------------------- helpers

    private function as(?User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function base(JobCard $job): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}";
    }

    private function jobInProgress(string $plan = 'ea-360', string $oil = 'fully-synthetic'): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 12000,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'package_id' => ServicePackage::where('slug', $plan)->value('id'),
            'oil_type_id' => OilType::where('slug', $oil)->value('id'),
            'service_type' => 'scheduled',
        ])->assertCreated()->json('data.id');

        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
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

    /**
     * Draft with the three additional jobs from the sample sheet.
     */
    private function draftWithExtras(JobCard $job): array
    {
        $estimate = $this->draft($job);
        foreach ([['Fog Lamp Bulb LHS', 'part', 375], ['Wiper Blade Set', 'part', 550], ['Throttle Body Service', 'labor', 1770]] as [$desc, $kind, $rate]) {
            $estimate = $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/lines", [
                'kind' => $kind, 'description' => $desc, 'quantity' => 1, 'rate' => $rate,
            ])->assertCreated()->json('data');
        }

        return $estimate;
    }

    private function send(JobCard $job, array $estimate): array
    {
        return $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/send")->assertOk()->json('data');
    }

    private function token(JobCard $job): string
    {
        return CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
    }

    private function publicDecide(string $token, array $decisions)
    {
        return $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => collect($decisions)->map(fn ($d, $k) => ['line_key' => $k, 'decision' => $d])->values()->all(),
        ]);
    }

    private function keys(array $estimate): array
    {
        return collect($estimate['additional_lines'])->pluck('line_key', 'description')->all();
    }

    // ------------------------------------------------------------------ tests

    public function test_price_masters_match_the_owner_sheet(): void
    {
        $this->assertSame(65, PackagePricing::count());
        $this->assertSame(26, PackageLineItem::count());
        $this->assertSame(7, VehicleCategory::where('is_active', true)->count());
        $this->assertSame('Triple-Synthetic', OilType::where('slug', 'top-tier-synthetic')->value('name'));
        $this->assertTrue(ServicePackage::where('slug', 'ea-elite')->value('send_customer_report'));
        $this->assertFalse(ServicePackage::where('slug', 'ea-pro')->value('send_customer_report'));

        // Re-seeding keeps prices the owner changed in the app
        $row = PackagePricing::firstOrFail();
        $row->update(['price' => 1234, 'customized_at' => now()]);
        $this->seed(ServicePackagesSeeder::class);
        $this->assertEquals(1234, $row->fresh()->price);
    }

    public function test_plan_block_balances_to_the_package_price_like_the_sheet(): void
    {
        $job = $this->jobInProgress();
        $estimate = $this->draft($job);

        $this->assertEquals(9575, $estimate['package_price']);
        $plan = collect($estimate['plan_lines']);
        $this->assertSame('oil', $plan->first()['kind']);
        $this->assertEquals(4, $plan->first()['quantity']);
        $this->assertEquals(3500, $plan->first()['amount']);
        $this->assertCount(10, $plan); // oil + 8 plan items + labour
        $this->assertEquals(9575 - 3500 - 4750, $plan->last()['amount']);
        $this->assertEquals(9575, $plan->sum('amount'));
        $this->assertEquals(9575, $estimate['grand_total']);
        $this->assertSame([], $estimate['warnings']);

        // Fewer litres (as on the sample: 3.5 L) → labour absorbs the difference
        $oil = $plan->first();
        $estimate = $this->as($this->advisor)->putJson($this->base($job) . "/estimates/{$estimate['id']}/lines/{$oil['id']}", ['quantity' => 3.5])
            ->assertOk()->json('data');
        $this->assertEquals(9575, collect($estimate['plan_lines'])->sum('amount'));
        $this->assertEquals(9575, $estimate['plan_total']);
    }

    public function test_negative_labour_is_flagged(): void
    {
        // Mid-size car on Elite + semi-synthetic: the sheet's items exceed the package price
        $job = $this->jobInProgress('ea-elite', 'semi-synthetic');

        $estimate = $this->draft($job);

        $this->assertNotEmpty($estimate['warnings']);
        $this->assertLessThan(0, collect($estimate['plan_lines'])->last()['amount']);
    }

    public function test_discounts_and_grand_total_in_words(): void
    {
        $job = $this->jobInProgress();
        $estimate = $this->draftWithExtras($job);
        $this->assertEquals(12270, $estimate['grand_total']);
        $this->assertSame('Rupees Twelve Thousand Two Hundred Seventy Only', $estimate['grand_total_words']);

        $estimate = $this->as($this->advisor)->putJson($this->base($job) . "/estimates/{$estimate['id']}", [
            'plan_discount_pct' => 10,
            'extras_discount_flat' => 95,
        ])->assertOk()->json('data');

        // 10% of 9575 = 957.5 → 958 (rounded to the rupee, as on the sheet)
        $this->assertEquals(8617, $estimate['plan_total']);
        $this->assertEquals(2600, $estimate['extras_total']);
        $this->assertEquals(11217, $estimate['grand_total']);
    }

    public function test_send_then_customer_approves_by_link_and_work_resumes(): void
    {
        $job = $this->jobInProgress();
        $result = $this->send($job, $this->draftWithExtras($job));

        $this->assertSame('awaiting_approval', $result['moved_to']);
        $this->assertSame('awaiting_approval', $job->fresh()->status);
        $this->assertSame('prepared', $result['message']['status']);
        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $result['message']['share_url']);
        $this->assertStringContainsString('3 additional item(s) need your approval', $result['message']['body']);

        // Work resumes by itself; not even a manager can move it by hand while items are pending
        $this->as($this->advisor)->patchJson($this->base($job) . '/status', ['status' => 'work_in_progress'])->assertForbidden();
        $codes = collect($this->as($this->owner)->patchJson($this->base($job) . '/status', ['status' => 'work_in_progress', 'notes' => 'Customer said yes'])
            ->assertStatus(422)->json('errors.guards'))->pluck('code');
        $this->assertContains('estimate_pending', $codes->all());

        $token = $this->token($job);
        $keys = $this->keys($result['estimate']);

        $page = $this->getJson("/api/v1/public/j/{$token}")->assertOk()->json('data');
        $this->assertSame(3, $page['estimate']['pending_count']);
        $this->assertArrayNotHasKey('warnings', $page['estimate']);

        $page = $this->publicDecide($token, [
            $keys['Fog Lamp Bulb LHS'] => 'approved',
            $keys['Wiper Blade Set'] => 'approved',
            $keys['Throttle Body Service'] => 'declined',
        ])->assertOk()->json('data');

        $this->assertSame(0, $page['estimate']['pending_count']);
        $this->assertEquals(9575 + 925, $page['estimate']['grand_total']);
        $declined = collect($page['estimate']['additional_lines'])->firstWhere('approval_status', 'declined');
        $this->assertSame('Recommended but not approved by customer', $declined['approval_label']);

        // Everything answered; the two approved parts are not in yet, so the job waits for them
        $job->refresh();
        $this->assertSame('awaiting_parts', $job->status);
        $this->assertTrue($job->customer_approved);
        $this->assertSame(2, JobCardItem::where('job_card_id', $job->id)->whereNotNull('estimate_line_key')->count());
        $this->assertSame(3, JobCardEstimateDecision::where('job_card_id', $job->id)->where('decided_via', 'customer_link')->count());

        // Customer changes their mind (still allowed while work is in progress)
        $this->publicDecide($token, [$keys['Wiper Blade Set'] => 'declined'])->assertOk();
        $this->assertSame(1, JobCardItem::where('job_card_id', $job->id)->whereNotNull('estimate_line_key')->count());
    }

    public function test_decisions_on_behalf_need_a_note_are_logged_and_tell_the_customer(): void
    {
        $job = $this->jobInProgress();
        $result = $this->send($job, $this->draftWithExtras($job));
        $keys = $this->keys($result['estimate']);
        $url = $this->base($job) . '/estimate-decisions';
        $body = ['decisions' => [['line_key' => $keys['Wiper Blade Set'], 'decision' => 'approved']]];

        $this->as($this->advisor)->postJson($url, $body)->assertStatus(422);

        $data = $this->as($this->advisor)->postJson($url, [...$body, 'note' => 'Customer approved on phone call at 11:30'])
            ->assertOk()->json('data');

        $this->assertSame('decision_recorded', $data['message']['template']);
        $this->assertStringContainsString('Wiper Blade Set', $data['message']['body']);
        $this->assertStringContainsString($this->advisor->name, $data['message']['body']);

        $audit = JobCardEstimateDecision::where('job_card_id', $job->id)->sole();
        $this->assertSame('advisor_on_behalf', $audit->decided_via);
        $this->assertSame($this->advisor->id, $audit->user_id);
        $this->assertSame($data['message']['id'], $audit->customer_message_id);

        // The customer sees who recorded it, and the message cannot be dismissed
        $line = collect($this->getJson('/api/v1/public/j/' . $this->token($job))->json('data.estimate.additional_lines'))
            ->firstWhere('line_key', $keys['Wiper Blade Set']);
        $this->assertSame('advisor_on_behalf', $line['decided_via']);
        $this->assertSame($this->advisor->name, $line['decided_by']);

        $this->as($this->advisor)->postJson($this->base($job) . "/customer-messages/{$data['message']['id']}/dismiss")->assertStatus(422);
        $this->as($this->advisor)->postJson($this->base($job) . "/customer-messages/{$data['message']['id']}/sent")->assertOk()
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_new_version_carries_decisions_and_resets_changed_lines(): void
    {
        $job = $this->jobInProgress();
        $v1 = $this->send($job, $this->draftWithExtras($job))['estimate'];
        $keys = $this->keys($v1);
        $token = $this->token($job);

        $this->publicDecide($token, [$keys['Fog Lamp Bulb LHS'] => 'approved', $keys['Wiper Blade Set'] => 'approved'])->assertOk();

        $v2 = $this->draft($job);
        $this->assertSame(2, $v2['version']);
        $wiper = collect($v2['additional_lines'])->firstWhere('description', 'Wiper Blade Set');
        $this->assertSame('approved', $wiper['approval_status']);

        $v2 = $this->as($this->advisor)->putJson($this->base($job) . "/estimates/{$v2['id']}/lines/{$wiper['id']}", ['rate' => 650])
            ->assertOk()->json('data');
        $this->assertSame('pending', collect($v2['additional_lines'])->firstWhere('description', 'Wiper Blade Set')['approval_status']);
        $this->assertSame('approved', collect($v2['additional_lines'])->firstWhere('description', 'Fog Lamp Bulb LHS')['approval_status']);

        $this->send($job, $v2);
        $this->assertSame(JobCardEstimate::STATUS_SUPERSEDED, JobCardEstimate::find($v1['id'])->status);

        // The customer link shows v2 and the same token still works
        $this->assertSame(2, $this->getJson("/api/v1/public/j/{$token}")->json('data.estimate.version'));
        // A sent estimate cannot be edited directly
        $this->as($this->advisor)->putJson($this->base($job) . "/estimates/{$v2['id']}", ['notes' => 'x'])->assertStatus(422);
    }

    public function test_bad_revoked_and_expired_links_are_rejected(): void
    {
        $job = $this->jobInProgress();
        $this->send($job, $this->draftWithExtras($job));
        $token = $this->token($job);

        $this->getJson('/api/v1/public/j/' . str_repeat('x', 40))->assertNotFound();
        $this->getJson('/api/v1/public/j/' . substr($token, 0, 39) . 'Z')->assertNotFound();

        CustomerLink::where('job_card_id', $job->id)->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/public/j/{$token}")->assertNotFound();

        CustomerLink::where('job_card_id', $job->id)->update(['expires_at' => now()->addDay()]);
        $this->getJson("/api/v1/public/j/{$token}")->assertOk();

        $this->as($this->advisor)->deleteJson($this->base($job) . '/customer-link')->assertOk();
        $this->getJson("/api/v1/public/j/{$token}")->assertNotFound();
    }

    public function test_customer_cannot_decide_lines_of_another_job_or_after_work_is_done(): void
    {
        $job = $this->jobInProgress();
        $this->send($job, $this->draftWithExtras($job));
        $other = $this->jobInProgress();
        $otherKeys = $this->keys($this->send($other, $this->draftWithExtras($other))['estimate']);

        $this->publicDecide($this->token($job), [$otherKeys['Wiper Blade Set'] => 'approved'])->assertNotFound();

        $job->forceFill(['status' => 'post_work_trial'])->save();
        $keys = $this->keys(collect($this->getJson('/api/v1/public/j/' . $this->token($job))->json('data'))->get('estimate'));
        $this->publicDecide($this->token($job), [$keys['Wiper Blade Set'] => 'approved'])->assertStatus(422);
    }

    public function test_report_is_released_only_for_plans_that_include_it(): void
    {
        $pro = $this->jobInProgress('ea-pro');
        $this->as($this->advisor)->postJson($this->base($pro) . '/report/release')->assertStatus(422);
        $this->as($this->advisor)->getJson($this->base($pro) . '/report')->assertOk()
            ->assertJsonPath('data.sends_to_customer', false);

        $job = $this->jobInProgress('ea-360');
        $this->as($this->advisor)->putJson($this->base($job) . '/report', ['include_activities' => false])->assertOk();
        $data = $this->as($this->advisor)->postJson($this->base($job) . '/report/release')->assertOk()->json('data');

        $this->assertSame('report_ready', $data['message']['template']);
        $page = $this->getJson('/api/v1/public/j/' . $this->token($job))->json('data');
        $this->assertSame([], $page['report']['sections']);
        $this->assertSame(CustomerMessage::PREPARED, CustomerMessage::where('job_card_id', $job->id)->where('template', 'report_ready')->value('status'));
    }

    public function test_estimate_pdf_renders(): void
    {
        $job = $this->jobInProgress();
        $estimate = $this->send($job, $this->draftWithExtras($job))['estimate'];

        $response = $this->as($this->advisor)->get($this->base($job) . "/estimates/{$estimate['id']}/pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $this->get('/api/v1/public/j/' . $this->token($job) . '/estimate.pdf')->assertOk();
    }

    public function test_pricing_masters_are_editable_by_the_owner_only(): void
    {
        $pkg = ServicePackage::where('slug', 'ea-360')->value('id');
        $cat = VehicleCategory::where('slug', 'sedan-compact-suv')->value('id');
        $oil = OilType::where('slug', 'fully-synthetic')->value('id');
        $body = ['package_id' => $pkg, 'category_id' => $cat, 'oil_type_id' => $oil, 'price' => 9999];

        $this->as($this->advisor)->getJson('/api/v1/workshop/pricing-masters')->assertOk();
        $this->as($this->advisor)->putJson('/api/v1/workshop/pricing-masters/prices', $body)->assertForbidden();
        $this->as($this->owner)->putJson('/api/v1/workshop/pricing-masters/prices', $body)->assertOk();

        $job = $this->jobInProgress();
        $this->assertEquals(9999, $this->draft($job)['package_price']);
    }
}
