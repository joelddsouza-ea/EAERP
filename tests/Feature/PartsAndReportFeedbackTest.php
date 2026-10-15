<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\SparePartRequest;
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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CompletesIntake;
use Tests\TestCase;

/**
 * Feedback round: who hears about parts prices, typed-in parts reaching the Parts Desk,
 * flagged activities completing with their line, "Submit for Report", and observations
 * (with remarks) on the estimate instead of declined lines.
 */
class PartsAndReportFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $manager;
    private User $advisor;
    private User $tech;
    private User $coordinator;
    private int $branchId;
    private JobCard $job;

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

        $this->owner = User::where('email', 'admin@expressautocare.com')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.com')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.com')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.com')->firstOrFail();
        $this->coordinator = User::where('email', 'parts@expressautocare.com')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Feedback Test', 'phone' => '9000000044']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH06FEED',
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ]);

        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_type' => 'scheduled',
            'package_id' => ServicePackage::where('slug', 'ea-refresh')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'km_reading' => 15000,
            'fuel_level' => 'half',
            'complaints' => ['Brake noise'],
        ])->assertCreated()->json('data.id');
        $this->job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $this->job->update(['service_advisor_id' => $this->advisor->id]);
        $this->as($this->advisor)->postJson($this->url('/technicians'), ['user_id' => $this->tech->id])->assertOk();
        $this->completeIntake($this->job, $this->advisor->id);
        // Intake complete + technician assigned: work starts by itself
        $this->assertSame('work_in_progress', $this->job->fresh()->status);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/workshop/job-cards/{$this->job->id}{$path}";
    }

    private function kinds(User $user): array
    {
        return $user->notifications()->get()->pluck('data.kind')->all();
    }

    private function sendAndToken(array $estimate): string
    {
        $this->as($this->advisor)->postJson($this->url("/estimates/{$estimate['id']}/send"))->assertOk();

        return CustomerLink::where('job_card_id', $this->job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
    }

    public function test_prices_from_the_coordinator_go_to_the_advisor_owner_and_manager(): void
    {
        $line = collect($this->as($this->tech)->getJson($this->url('/issues'))->json('data'))->first();
        $this->as($this->tech)->post($this->url("/issues/{$line['id']}/media"), ['file' => UploadedFile::fake()->image('f.jpg')])->assertCreated();
        $partId = $this->as($this->tech)->postJson($this->url("/issues/{$line['id']}/parts"), ['part_name' => 'Brake pads', 'quantity' => 2])
            ->assertCreated()->json('data.id');
        $this->as($this->tech)->postJson($this->url('/parts-submission'))->assertOk();
        $this->as($this->advisor)->postJson($this->url('/part-requests/forward'), ['ids' => [$partId]])->assertOk();

        $quote = $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$partId}/quotes", [
            'supplier_name' => 'Pads Co', 'unit_cost' => 800, 'gst_mode' => 'incl',
        ])->json('data.id');
        $this->as($this->coordinator)->postJson('/api/v1/workshop/parts-desk/submit', ['selections' => [$partId => $quote]])->assertOk();

        foreach ([$this->advisor, $this->owner, $this->manager] as $user) {
            $this->assertContains('parts_quoted', $this->kinds($user), $user->email);
        }
        $this->assertNotContains('parts_quoted', $this->kinds($this->tech));

        // Quantity carries to the estimate: 2 × 800 at 0% margin = 1600
        $estimate = $this->as($this->advisor)->postJson($this->url('/estimates'))->json('data');
        $this->as($this->advisor)->postJson($this->url("/estimates/{$estimate['id']}/parts"), ['spare_part_request_id' => $partId, 'margin_pct' => 0])
            ->assertCreated();
        $this->assertEquals(1600, JobCardEstimateLine::where('spare_part_request_id', $partId)->value('amount'));
    }

    public function test_a_part_typed_on_the_estimate_reaches_the_parts_desk_once_approved(): void
    {
        $estimate = $this->as($this->advisor)->postJson($this->url('/estimates'))->json('data');
        $data = $this->as($this->advisor)->postJson($this->url("/estimates/{$estimate['id']}/lines"), [
            'kind' => 'part', 'description' => 'Wiper blades', 'quantity' => 2, 'rate' => 450,
        ])->assertCreated()->json('data');
        $token = $this->sendAndToken($estimate);

        $key = collect($data['additional_lines'])->firstWhere('description', 'Wiper blades')['line_key'];
        $this->postJson("/api/v1/public/j/{$token}/decisions", ['decisions' => [['line_key' => $key, 'decision' => 'approved']]])->assertOk();

        $procure = $this->as($this->coordinator)->getJson('/api/v1/workshop/parts-desk?tab=procure')->json('data.requests');
        $wipers = collect($procure)->firstWhere('part_name', 'Wiper blades');
        $this->assertSame('approved', $wipers['status']);
        $this->assertEquals(2, $wipers['quantity']);
        $this->assertContains('parts_to_procure', $this->kinds($this->coordinator));

        // Received parts stay listed (To procure for 14 days, All parts)
        $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$wipers['id']}/received")->assertOk();
        $this->assertContains('Wiper blades', array_column($this->as($this->coordinator)->getJson('/api/v1/workshop/parts-desk?tab=procure')->json('data.requests'), 'part_name'));
        $this->assertContains('Wiper blades', array_column($this->as($this->coordinator)->getJson('/api/v1/workshop/parts-desk?tab=all')->json('data.requests'), 'part_name'));
    }

    public function test_a_flagged_activity_is_done_when_its_line_is_done(): void
    {
        $sheet = JobCardChecklist::where('job_card_id', $this->job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        $item = $sheet->items()->where('media_requirement', 'none')->firstOrFail();
        $this->as($this->tech)->patchJson($this->url("/job-sheets/{$sheet->id}/items/{$item->id}"), ['needs_attention' => true, 'remarks' => 'Worn'])->assertOk();

        $line = collect($this->as($this->tech)->getJson($this->url('/issues'))->json('data'))->firstWhere('source', 'activity');
        $this->as($this->tech)->post($this->url("/issues/{$line['id']}/media"), ['file' => UploadedFile::fake()->image('w.jpg')])->assertCreated();
        $this->as($this->tech)->patchJson($this->url("/issues/{$line['id']}"), ['status' => 'resolved'])->assertOk();
        $this->assertSame(JobCardChecklistItem::STATUS_DONE, $item->fresh()->status);

        $this->as($this->tech)->patchJson($this->url("/issues/{$line['id']}"), ['status' => 'in_progress'])->assertOk();
        $this->assertSame(JobCardChecklistItem::STATUS_PENDING, $item->fresh()->status);

        // Deferred: the activity no longer holds up the sheet
        $this->as($this->tech)->patchJson($this->url("/issues/{$line['id']}"), ['status' => 'deferred', 'resolution_notes' => 'Next service'])->assertOk();
        $problems = $this->as($this->tech)->postJson($this->url("/job-sheets/{$sheet->id}/submit"))->json('errors.items') ?? [];
        $this->assertNotContains($item->id, array_column($problems, 'item_id'));
    }

    public function test_submit_for_report_and_observations_on_the_estimate(): void
    {
        $this->as($this->advisor)->postJson($this->url('/observations/submit'))->assertForbidden();
        $this->as($this->tech)->postJson($this->url('/observations/submit'))->assertStatus(422);
        $this->as($this->tech)->postJson($this->url('/observations'), ['text' => 'Tyres at 3 mm'])->assertCreated();
        $this->as($this->tech)->postJson($this->url('/observations/submit'))->assertOk();
        $this->assertContains('observations_submitted', $this->kinds($this->advisor));

        // A declined line moves from Additional Parts & Labour to the observations, with its remark
        $estimate = $this->as($this->advisor)->postJson($this->url('/estimates'))->json('data');
        $data = $this->as($this->advisor)->postJson($this->url("/estimates/{$estimate['id']}/lines"), [
            'kind' => 'labor', 'description' => 'Throttle body cleaning', 'rate' => 1770,
        ])->json('data');
        $token = $this->sendAndToken($estimate);
        $key = collect($data['additional_lines'])->firstWhere('description', 'Throttle body cleaning')['line_key'];
        $this->postJson("/api/v1/public/j/{$token}/decisions", ['decisions' => [['line_key' => $key, 'decision' => 'declined']]])->assertOk();

        $public = $this->getJson("/api/v1/public/j/{$token}")->json('data.estimate');
        $declined = collect($public['observations'])->firstWhere('estimate_line_key', $key);
        $this->assertSame('Customer not approved', $declined['remark']);
        $this->assertContains('Tyres at 3 mm', array_column($public['observations'], 'text'));

        $this->get("/api/v1/public/j/{$token}/estimate.pdf")->assertOk();
    }
}
