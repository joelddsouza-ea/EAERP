<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Models\ObservationRemark;
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
 * Additional Issues lines (customer complaints from intake + the technician's findings),
 * their photos/videos and parts, the observations raised from declined or deferred
 * work with remarks, and media on body-shop panels.
 */
class AdditionalIssuesTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private User $coordinator;
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

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->coordinator = User::where('email', 'parts@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Lines Test', 'phone' => '9000000066']);
        $this->customerId = $customer->id;
        $this->vehicleId = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH04LINE',
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ])->id;
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function url(JobCard $job, string $path = ''): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}{$path}";
    }

    /**
     * EA Refresh job with two complaint lines, at work with Technician One.
     */
    private function jobAtWork(array $complaints = ['Brake noise when stopping', 'AC not cooling'], array $extra = []): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 12000,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'service_type' => 'scheduled',
            'package_id' => ServicePackage::where('slug', 'ea-refresh')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'complaints' => $complaints,
            ...$extra,
        ])->assertCreated()->json('data.id');

        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $job->update(['service_advisor_id' => $this->advisor->id]);
        $job->update(['km_reading' => 30000, 'fuel_level' => 'half', 'technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        // Intake complete + technician assigned: work starts by itself
        $this->assertSame('work_in_progress', $job->fresh()->status);

        return $job->fresh();
    }

    private function photo(JobCard $job, int $issueId, ?string $tag = null)
    {
        return $this->as($this->tech)->post($this->url($job, "/issues/{$issueId}/media"), array_filter([
            'file' => UploadedFile::fake()->image('p.jpg', 300, 200),
            'tag' => $tag,
        ]));
    }

    /**
     * The technician's new line, with the photo of the faulty part.
     */
    private function finding(JobCard $job, string $text): array
    {
        return $this->as($this->tech)->post($this->url($job, '/issues'), [
            'description' => $text,
            'files' => [UploadedFile::fake()->image('fault.jpg', 300, 200)],
        ])->assertCreated()->json('data');
    }

    public function test_intake_complaints_become_lines_on_the_job_sheet(): void
    {
        $job = $this->jobAtWork(['Brake noise when stopping', '  ', 'AC not cooling']);

        $lines = $this->as($this->tech)->getJson($this->url($job, '/issues'))->assertOk()->json('data');
        $this->assertSame(['Brake noise when stopping', 'AC not cooling'], array_column($lines, 'description'));
        $this->assertSame(['customer', 'customer'], array_column($lines, 'source'));
        $this->assertSame("Brake noise when stopping\nAC not cooling", $job->customer_complaints);

        // The advisor keeps complaint lines; the technician adds his findings — with evidence of the faulty part
        $this->as($this->advisor)->postJson($this->url($job, '/issues'), ['description' => 'Wipers smear'])
            ->assertCreated()->assertJsonPath('data.source', 'customer');
        $this->as($this->tech)->postJson($this->url($job, '/issues'), ['description' => 'Front pads at 2 mm'])
            ->assertStatus(422)->assertJsonValidationErrors('files');
        $finding = $this->finding($job, 'Front pads at 2 mm');
        $this->assertSame('technician', $finding['source']);
        $this->assertCount(1, $finding['media']);
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$lines[0]['id']}"), ['description' => 'x'])->assertForbidden();
    }

    public function test_evidence_then_parts_then_new_part_photo_then_done(): void
    {
        $job = $this->jobAtWork(['Brake noise when stopping']);
        $complaint = JobCardIssue::where('job_card_id', $job->id)->firstOrFail();
        $parts = fn (int $issueId, string $name) => $this->as($this->tech)
            ->postJson($this->url($job, "/issues/{$issueId}/parts"), ['part_name' => $name]);

        // 1. Evidence of the faulty part before the part required can be listed
        $parts($complaint->id, 'Front pad set')->assertStatus(422);
        $this->photo($job, $complaint->id)->assertCreated()->assertJsonPath('data.can_list_parts', true);
        $partId = $parts($complaint->id, 'Front pad set')->assertCreated()->json('data.id');

        // 2. Parts go to the advisor; the line waits for the estimate and approval
        $this->as($this->tech)->postJson($this->url($job, '/parts-submission'))->assertOk();
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$complaint->id}"), ['status' => 'resolved'])
            ->assertStatus(422)->assertJsonPath('message', "Waiting for the parts estimate and the customer's approval.");

        // 3. Approved, not arrived: no new-part photo yet, can't be done
        SparePartRequest::whereKey($partId)->update(['status' => SparePartRequest::APPROVED]);
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$complaint->id}"), ['status' => 'resolved'])
            ->assertStatus(422)->assertJsonPath('message', 'Waiting for the new part to arrive.');
        $this->photo($job, $complaint->id, 'fitted_part')->assertStatus(422);

        // 4. Arrived: photo of the new part fitted, then done
        $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$partId}/received")->assertOk();
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$complaint->id}"), ['status' => 'resolved'])
            ->assertStatus(422)->assertJsonPath('message', 'Add a photo or video of the new part after fitting it.');
        $this->photo($job, $complaint->id, 'fitted_part')->assertCreated()->assertJsonPath('data.parts_arrived', true);
        $this->as($this->advisor)->patchJson($this->url($job, "/issues/{$complaint->id}"), ['status' => 'resolved'])->assertForbidden();
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$complaint->id}"), ['status' => 'resolved'])->assertOk();

        // A finding still open blocks the final job sheet submit
        $finding = $this->finding($job, 'Rear wiper blade torn');
        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        foreach ($sheet->items as $item) {
            if ($item->media_requirement !== 'none') {
                $this->as($this->tech)->post($this->url($job, "/job-sheets/{$sheet->id}/items/{$item->id}/media"), [
                    'file' => UploadedFile::fake()->image('a.jpg', 200, 150),
                ])->assertCreated();
            }
            $this->as($this->tech)->patchJson($this->url($job, "/job-sheets/{$sheet->id}/items/{$item->id}"), ['status' => 'done'])->assertOk();
        }
        $problems = $this->as($this->tech)->postJson($this->url($job, "/job-sheets/{$sheet->id}/submit"))
            ->assertStatus(422)->json('errors.items');
        $this->assertSame([$finding['id']], array_column($problems, 'issue_id'));

        // Deferring needs a note and turns it into an observation ("Fix at next service")
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$finding['id']}"), ['status' => 'deferred'])->assertStatus(422);
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$finding['id']}"), [
            'status' => 'deferred', 'resolution_notes' => 'Customer will replace it himself',
        ])->assertOk();
        $obs = JobCardObservation::where('job_card_issue_id', $finding['id'])->firstOrFail();
        $this->assertSame(JobCardObservation::SOURCE_ISSUE_DEFERRED, $obs->source);
        $this->assertSame(ObservationRemark::FIX_AT_NEXT_SERVICE, $obs->remark->code);

        $this->as($this->tech)->postJson($this->url($job, "/job-sheets/{$sheet->id}/submit"))->assertOk();
        $this->assertContains('job_sheet_submitted', $this->advisor->notifications()->get()->pluck('data.kind')->all());
    }

    public function test_a_flagged_activity_is_an_additional_issues_line(): void
    {
        $job = $this->jobAtWork([]);
        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        $item = $sheet->items()->where('media_requirement', '!=', 'none')->firstOrFail();
        $itemUrl = $this->url($job, "/job-sheets/{$sheet->id}/items/{$item->id}");

        // Photo on the activity, then ⚠ with remarks
        $this->as($this->tech)->post("{$itemUrl}/media", ['file' => UploadedFile::fake()->image('leak.jpg', 300, 200)])->assertCreated();
        $this->as($this->tech)->patchJson($itemUrl, ['needs_attention' => true, 'remarks' => 'Oil seepage at sump'])->assertOk();

        $line = collect($this->as($this->tech)->getJson($this->url($job, '/issues'))->json('data'))->firstWhere('source', 'activity');
        $this->assertSame("{$item->item_name}: Oil seepage at sump", $line['description']);
        $this->assertCount(1, $line['activity_media']);
        // The activity's photo is the evidence: parts can be listed straight away
        $this->assertTrue($line['can_list_parts']);
        $this->as($this->tech)->postJson($this->url($job, "/issues/{$line['id']}/parts"), ['part_name' => 'Sump gasket'])->assertCreated();

        // Not an observation any more
        $this->assertFalse(collect($this->as($this->tech)->getJson($this->url($job, '/observations'))->json('data'))->contains('source', 'activity'));

        // Its text follows the activity; it is removed by clearing the flag
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$line['id']}"), ['description' => 'x'])->assertStatus(422);
        $this->as($this->tech)->deleteJson($this->url($job, "/issues/{$line['id']}"))->assertStatus(422);
    }

    public function test_declined_lines_become_observations_with_remarks(): void
    {
        $job = $this->jobAtWork([]);
        $estimate = $this->as($this->advisor)->postJson($this->url($job, '/estimates'))->assertOk()->json('data');
        $data = $this->as($this->advisor)->postJson($this->url($job, "/estimates/{$estimate['id']}/lines"), [
            'kind' => 'labor', 'description' => 'Throttle body servicing', 'rate' => 1770,
        ])->assertCreated()->json('data');
        $this->as($this->advisor)->postJson($this->url($job, "/estimates/{$estimate['id']}/send"))->assertOk();

        $line = JobCardEstimateLine::where('description', 'Throttle body servicing')->latest('id')->firstOrFail();
        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => [['line_key' => $line->line_key, 'decision' => 'declined']],
        ])->assertOk();

        $observations = $this->as($this->tech)->getJson($this->url($job, '/observations'))->assertOk()->json('data');
        $declined = collect($observations)->firstWhere('source', 'estimate_declined');
        $this->assertSame('Throttle body servicing', $declined['text']);
        $this->assertSame('Customer not approved', $declined['remark_text']);

        // The advisor picks the advice ("Fix within 30 days") but can't rewrite the text
        $within = ObservationRemark::where('code', 'fix_within_days')->value('id');
        $this->as($this->advisor)->patchJson($this->url($job, "/observations/{$declined['id']}"), ['remark_id' => $within, 'remark_days' => 30])
            ->assertOk()->assertJsonPath('data.remark_text', 'Fix within 30 days');
        $this->as($this->advisor)->patchJson($this->url($job, "/observations/{$declined['id']}"), ['text' => 'x'])->assertForbidden();

        // On the report, under "not approved", with the advice
        $report = $this->as($this->advisor)->getJson($this->url($job, '/report'))->assertOk()->json('data');
        $this->assertContains('Throttle body servicing — Fix within 30 days', $report['not_approved']);

        // Approved later: the observation goes away
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => [['line_key' => $line->line_key, 'decision' => 'approved']],
        ])->assertOk();
        $this->assertNull(JobCardObservation::find($declined['id']));
    }

    public function test_remarks_and_line_media_minimums_are_masters(): void
    {
        $this->as($this->advisor)->postJson('/api/v1/workshop/job-sheet-masters/observation-remarks', ['label' => 'x'])->assertForbidden();

        $remark = $this->as($this->owner)->postJson('/api/v1/workshop/job-sheet-masters/observation-remarks', [
            'label' => 'Replace within _ km',
        ])->assertCreated()->json('data');
        $this->assertTrue($remark['needs_days']);
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/observation-remarks/{$remark['id']}", ['is_active' => false])->assertOk();
        $this->assertNotContains($remark['id'], array_column(
            $this->as($this->tech)->getJson('/api/v1/workshop/observation-remarks')->json('data'), 'id'
        ));

        $this->as($this->owner)->putJson('/api/v1/workshop/intake-requirements', ['issue_min_problem_media' => 0])
            ->assertOk()->assertJsonPath('data.issue_min_problem_media', 0);

        // With no evidence required, a finding can be closed straight away
        $job = $this->jobAtWork([]);
        $finding = $this->as($this->tech)->postJson($this->url($job, '/issues'), ['description' => 'Loose heat shield'])->json('data');
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$finding['id']}"), ['status' => 'resolved'])->assertOk();
    }

    public function test_body_shop_panels_can_require_photos(): void
    {
        $job = $this->jobAtWork([], ['service_type' => 'body_shop', 'package_id' => null, 'oil_type_id' => null]);
        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'body_shop')->firstOrFail();
        $item = $sheet->items()->firstOrFail();

        // The master asks for a photo on every panel that is worked on
        ChecklistItem::whereKey($item->checklist_item_id)->update(['media_requirement' => 'photo']);
        $item->update(['media_requirement' => 'photo', 'min_media_count' => 1]);

        $this->as($this->tech)->patchJson($this->url($job, "/job-sheets/{$sheet->id}/items/{$item->id}"), [
            'value' => ['repair' => true, 'replace' => false, 'partial_paint' => false, 'full_paint' => false],
        ])->assertOk();

        $problems = $this->as($this->tech)->postJson($this->url($job, "/job-sheets/{$sheet->id}/submit"))->assertStatus(422)->json('errors.items');
        $this->assertSame('media_missing', collect($problems)->firstWhere('item_id', $item->id)['code']);
    }
}
