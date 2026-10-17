<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CompletesIntake;
use Tests\TestCase;

/**
 * Job card life-cycle workflow and digital job sheets (Phase 1).
 */
class JobCardWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private User $otherTech;
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
        $this->otherTech = User::where('email', 'tech2@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Test Customer', 'phone' => '9000000099']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH01TEST',
            'category_id' => VehicleCategory::firstOrFail()->id,
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

    private function createJob(string $plan = 'ea-refresh'): JobCard
    {
        $packageId = ServicePackage::where('slug', $plan)->value('id');
        // A service plan needs an oil grade it is priced for on this car type
        $oilTypeId = PackagePricing::where('package_id', $packageId)
            ->where('category_id', Vehicle::find($this->vehicleId)->category_id)
            ->value('oil_type_id');

        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 12000,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'package_id' => $packageId,
            'oil_type_id' => $oilTypeId,
            'service_type' => 'scheduled',
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    private function moveTo(JobCard $job, string $status, User $user, array $extra = [])
    {
        return $this->as($user)->patchJson("/api/v1/workshop/job-cards/{$job->id}/status", ['status' => $status, ...$extra]);
    }

    /**
     * Intake done, technician assigned: work starts by itself.
     */
    private function jobInProgress(string $plan = 'ea-refresh'): JobCard
    {
        $job = $this->createJob($plan);
        $job->update(['km_reading' => 12000, 'fuel_level' => 'half', 'technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $this->assertSame('work_in_progress', $job->fresh()->status);

        return $job->fresh();
    }

    private function submitSheet(JobCard $job, JobCardChecklist $sheet, User $user)
    {
        return $this->as($user)->postJson("/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$sheet->id}/submit");
    }

    private function workSheet(JobCard $job): JobCardChecklist
    {
        return JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
    }

    private function itemUrl(JobCard $job, JobCardChecklistItem $item, string $suffix = ''): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$item->job_card_checklist_id}/items/{$item->id}{$suffix}";
    }

    private function completeWorkSheet(JobCard $job): void
    {
        foreach ($this->workSheet($job)->items as $item) {
            if ($item->media_requirement !== 'none') {
                $this->as($this->tech)->post($this->itemUrl($job, $item, '/media'), [
                    'file' => UploadedFile::fake()->image('p.jpg', 200, 150),
                ])->assertCreated();
            }
            $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['status' => 'done'])->assertOk();
        }
    }

    // ------------------------------------------------------------------ tests

    public function test_new_job_gets_plan_job_sheet_and_pre_delivery_checklist(): void
    {
        $job = $this->createJob('ea-refresh');

        $sheets = JobCardChecklist::where('job_card_id', $job->id)->withCount('items')->get()->keyBy('sheet_kind');

        $this->assertSame('intake_pending', $job->status);
        $this->assertSame(18, $sheets['service_activities']->items_count);
        // Three questions apply only to 360 and ELITE
        $this->assertSame(17, $sheets['pre_delivery']->items_count);
        $this->assertArrayNotHasKey('observation_report', $sheets);
    }

    public function test_elite_job_gets_all_pre_delivery_questions_and_observation_report(): void
    {
        $job = $this->createJob('ea-elite');

        $sheets = JobCardChecklist::where('job_card_id', $job->id)->withCount('items')->get()->keyBy('sheet_kind');

        $this->assertSame(81, $sheets['service_activities']->items_count);
        $this->assertSame(20, $sheets['pre_delivery']->items_count);
        $this->assertSame(53, $sheets['observation_report']->items_count);
    }

    public function test_cannot_skip_ahead_in_the_workflow(): void
    {
        $job = $this->createJob();

        $this->moveTo($job, 'delivered', $this->owner)->assertStatus(422);
        $this->assertSame('intake_pending', $job->fresh()->status);
    }

    public function test_work_needs_intake_details_and_a_technician(): void
    {
        $job = $this->createJob();

        // The first intake action starts intake by itself
        $this->as($this->advisor)->putJson("/api/v1/workshop/job-cards/{$job->id}/intake/trial", ['status' => 'done'])->assertOk();
        $this->assertSame('intake_in_progress', $job->fresh()->status);

        // Work starts by itself; by hand only an owner/manager, with a reason, and the checks still apply
        $this->moveTo($job, 'work_in_progress', $this->advisor)->assertForbidden();
        $this->moveTo($job, 'work_in_progress', $this->owner)->assertStatus(422);
        $codes = collect($this->moveTo($job, 'work_in_progress', $this->owner, ['notes' => 'Stuck'])->assertStatus(422)->json('errors.guards'))->pluck('code');

        $this->assertEqualsCanonicalizing(['intake_incomplete', 'technician_missing'], $codes->all());
    }

    public function test_media_is_required_before_a_starred_activity_is_done(): void
    {
        $job = $this->jobInProgress();
        $item = $this->workSheet($job)->items()->where('media_requirement', 'photo_or_video')->firstOrFail();

        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['status' => 'done'])
            ->assertStatus(422)->assertJsonPath('errors.media.0', fn ($m) => str_contains($m, 'required'));

        $this->as($this->tech)->post($this->itemUrl($job, $item, '/media'), ['file' => UploadedFile::fake()->image('oil.jpg', 400, 300)])
            ->assertCreated()->assertJsonPath('data.media_shortfall', null);

        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['status' => 'done'])->assertOk();
    }

    public function test_deleting_required_media_reverts_the_activity(): void
    {
        $job = $this->jobInProgress();
        $item = $this->workSheet($job)->items()->where('media_requirement', 'photo_or_video')->firstOrFail();

        $mediaId = $this->as($this->tech)->post($this->itemUrl($job, $item, '/media'), ['file' => UploadedFile::fake()->image('a.jpg')])
            ->json('data.media.id');
        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['status' => 'done'])->assertOk();

        $this->as($this->tech)->deleteJson($this->itemUrl($job, $item, "/media/{$mediaId}"))
            ->assertOk()->assertJsonPath('data.reverted_to_pending', true);
        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_video_is_rejected_on_a_photo_only_activity(): void
    {
        $job = $this->jobInProgress();
        $item = $this->workSheet($job)->items()->firstOrFail();
        $item->update(['media_requirement' => 'photo']);

        $this->as($this->tech)->post($this->itemUrl($job, $item, '/media'), [
            'file' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
        ])->assertStatus(422);
    }

    public function test_na_only_where_the_sheet_allows_it(): void
    {
        $job = $this->jobInProgress('ea-essentials');
        $items = $this->workSheet($job)->items;
        $naAllowed = $items->firstWhere('na_allowed', true);
        $naNotAllowed = $items->firstWhere('na_allowed', false);

        $this->as($this->tech)->patchJson($this->itemUrl($job, $naNotAllowed), ['status' => 'not_applicable'])->assertStatus(422);
        $this->as($this->tech)->patchJson($this->itemUrl($job, $naAllowed), ['status' => 'not_applicable'])->assertOk();
    }

    public function test_attention_flag_needs_remarks_and_adds_an_additional_issues_line(): void
    {
        $job = $this->jobInProgress();
        $item = $this->workSheet($job)->items()->firstOrFail();

        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['needs_attention' => true])->assertStatus(422);
        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['needs_attention' => true, 'remarks' => 'Leak at seal'])->assertOk();

        // Work to do, not an observation: it goes through evidence → parts → estimate like any other line
        $this->assertDatabaseHas('job_card_issues', [
            'job_card_id' => $job->id, 'source' => 'activity', 'source_item_id' => $item->id,
            'description' => "{$item->item_name}: Leak at seal",
        ]);
        $this->assertDatabaseMissing('job_card_observations', ['source_item_id' => $item->id]);

        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['needs_attention' => false])->assertOk();
        $this->assertDatabaseMissing('job_card_issues', ['source_item_id' => $item->id]);
    }

    public function test_technicians_only_work_on_their_own_jobs(): void
    {
        $job = $this->jobInProgress();
        $item = $this->workSheet($job)->items()->firstOrFail();

        $this->as($this->otherTech)->patchJson($this->itemUrl($job, $item), ['status' => 'done'])->assertForbidden();
    }

    public function test_sheet_items_are_scoped_to_their_job_card(): void
    {
        $jobA = $this->jobInProgress();
        $jobB = $this->createJob();
        $itemOfB = $this->workSheet($jobB)->items()->firstOrFail();
        $sheetOfA = $this->workSheet($jobA);

        $this->as($this->owner)
            ->patchJson("/api/v1/workshop/job-cards/{$jobA->id}/job-sheets/{$sheetOfA->id}/items/{$itemOfB->id}", ['status' => 'done'])
            ->assertNotFound();
    }

    public function test_signed_media_url_works_and_tampering_is_rejected(): void
    {
        $job = $this->jobInProgress();
        $item = $this->workSheet($job)->items()->where('media_requirement', 'photo_or_video')->firstOrFail();

        $url = $this->as($this->tech)->post($this->itemUrl($job, $item, '/media'), ['file' => UploadedFile::fake()->image('a.jpg')])
            ->json('data.media.url');

        $this->get($url)->assertOk();
        $this->get(substr($url, 0, -4) . 'abcd')->assertForbidden();
    }

    public function test_submitting_the_job_sheet_moves_the_car_to_the_trial_drive(): void
    {
        $job = $this->jobInProgress();
        $sheet = $this->workSheet($job);

        $this->submitSheet($job, $sheet, $this->tech)->assertStatus(422);
        $this->assertSame('work_in_progress', $job->fresh()->status);

        $this->completeWorkSheet($job);
        $this->assertSame('work_in_progress', $job->fresh()->status);

        $this->submitSheet($job, $sheet, $this->tech)->assertOk();
        $this->assertSame('post_work_trial', $job->fresh()->status);
        $this->assertSame('auto', $job->statusHistory()->latest('id')->first()->meta['via']);
    }

    public function test_not_done_activity_blocks_submission_unless_a_manager_overrides(): void
    {
        $job = $this->jobInProgress();
        $this->completeWorkSheet($job);
        $sheet = $this->workSheet($job);
        $item = $sheet->items()->firstOrFail();
        $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['status' => 'not_done', 'remarks' => 'Part unavailable'])->assertOk();

        $submit = "/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$sheet->id}/submit";
        $this->as($this->tech)->postJson($submit)->assertStatus(422);
        $this->as($this->tech)->postJson($submit, ['override' => true, 'notes' => 'x'])->assertForbidden();
        $this->as($this->owner)->postJson($submit, ['override' => true])->assertStatus(422);
        $this->as($this->owner)->postJson($submit, ['override' => true, 'notes' => 'Customer declined the part'])
            ->assertOk()->assertJsonPath('data.override', true);
    }

    public function test_status_override_needs_permission_and_a_note_and_is_logged(): void
    {
        $job = $this->jobInProgress();

        $this->moveTo($job, 'post_work_trial', $this->advisor, ['override' => true, 'notes' => 'x'])->assertForbidden();
        $this->moveTo($job, 'post_work_trial', $this->owner, ['override' => true])->assertStatus(422);
        $this->moveTo($job, 'post_work_trial', $this->owner, ['override' => true, 'notes' => 'Urgent customer'])->assertOk();

        $history = $job->statusHistory()->latest('id')->first();
        $this->assertTrue($history->meta['override']);
    }

    public function test_full_workflow_from_intake_to_delivery(): void
    {
        $job = $this->jobInProgress();
        $estimate = $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$job->id}/estimates")->assertOk()->json('data');
        $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$job->id}/estimates/{$estimate['id']}/send")->assertOk();
        // Nothing to approve: work goes on
        $this->assertSame('work_in_progress', $job->fresh()->status);

        $this->completeWorkSheet($job);
        $this->submitSheet($job, $this->workSheet($job), $this->tech)->assertOk();
        $this->assertSame('post_work_trial', $job->fresh()->status);

        // Post-service trial drive: issues found send it back to work by themselves (with the note)
        $post = "/api/v1/workshop/job-cards/{$job->id}/post-service";
        $this->as($this->advisor)->postJson("{$post}/trial", ['result' => 'issues'])->assertStatus(422);
        $this->as($this->advisor)->postJson("{$post}/trial", ['result' => 'issues', 'notes' => 'Wiper nozzle misaligned'])->assertOk();
        $this->assertSame('work_in_progress', $job->fresh()->status);
        // Sent back: the system waits; people move it on when the rework is done
        $this->as($this->tech)->getJson("/api/v1/workshop/job-cards/{$job->id}/transitions")->assertJsonPath('data.stage.sent_back', true);
        $this->moveTo($job, 'post_work_trial', $this->tech)->assertOk();

        // Trial OK → wash; washing done → final QC (each by itself)
        $this->as($this->advisor)->postJson("{$post}/wash")->assertStatus(422);
        $this->as($this->advisor)->postJson("{$post}/trial", ['result' => 'ok'])->assertOk();
        $this->assertSame('wash_pending', $job->fresh()->status);
        $pre = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'pre_delivery')->firstOrFail();
        $first = $pre->items()->firstOrFail();
        // QC only after the wash
        $this->as($this->advisor)->patchJson($this->itemUrl($job, $first), ['value' => 'yes'])->assertStatus(422);
        $this->as($this->advisor)->postJson("{$post}/wash")->assertOk();
        $this->assertSame('qc_pending', $job->fresh()->status);

        // Final QC (pre-delivery): technicians can't answer; every answer must match
        $this->as($this->tech)->patchJson($this->itemUrl($job, $first), ['value' => 'yes'])->assertForbidden();
        $this->submitSheet($job, $pre, $this->advisor)->assertStatus(422);
        foreach ($pre->items as $item) {
            $answered = $this->as($this->advisor)->patchJson($this->itemUrl($job, $item), ['value' => $item->expected_answer])
                ->assertOk()->json('data.item');
            // QC spot check: the point asks for a photo/video shot straight away
            if ($answered['qc_evidence'] ?? null) {
                $this->as($this->advisor)->post($this->itemUrl($job, $item, '/media'), [
                    'file' => UploadedFile::fake()->image('qc.jpg', 200, 150),
                    'captured_at' => now()->toIso8601String(),
                ])->assertCreated();
            }
        }
        $this->assertSame(3, $pre->items()->where('qc_evidence_required', true)->count());
        $this->submitSheet($job, $pre, $this->advisor)->assertOk();
        $this->assertSame('ready_for_delivery', $job->fresh()->status);

        // Bill approved by back office → Invoiced; paid in full → gate pass; vehicle out → delivered
        $billing = "/api/v1/workshop/job-cards/{$job->id}/billing";
        $bill = $this->as($this->owner)->postJson("{$billing}/proforma")->assertCreated()->json('data.bill');
        $this->as($this->owner)->postJson("{$billing}/bills/{$bill['id']}/approve")->assertOk();
        $this->assertSame('invoiced', $job->fresh()->status);

        // No gate pass, no delivery (not even by override)
        $this->moveTo($job, 'delivered', $this->owner, ['override' => true, 'notes' => 'x'])->assertStatus(422);
        $this->as($this->advisor)->postJson("{$billing}/gate-pass/vehicle-out")->assertStatus(422);

        $this->as($this->advisor)->postJson("{$billing}/payments", ['amount' => $bill['total'], 'method' => 'upi'])->assertCreated()
            ->assertJsonPath('data.gate_pass.override', false);
        $this->assertSame('gate_pass_generated', $job->fresh()->status);

        $this->as($this->advisor)->postJson("{$billing}/gate-pass/vehicle-out")->assertOk();

        $job->refresh();
        $this->assertSame('delivered', $job->status);
        $this->assertNotNull($job->work_started_at);
        $this->assertNotNull($job->time_out);
        $this->assertSame(2, $job->followUpCalls()->count());
        $this->assertSame(
            ['intake_pending', 'intake_in_progress', 'work_in_progress', 'post_work_trial', 'work_in_progress', 'post_work_trial',
             'wash_pending', 'qc_pending', 'ready_for_delivery', 'invoiced', 'gate_pass_generated', 'delivered'],
            $job->statusHistory()->orderBy('id')->pluck('to_status')->all()
        );
    }

    public function test_masters_edits_only_affect_new_job_cards(): void
    {
        $existing = $this->createJob();
        $existingItem = $this->workSheet($existing)->items()->where('media_requirement', 'none')->firstOrFail();

        $master = $existingItem->checklistItem;
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/sections/{$master->section_id}/items/{$master->id}", [
            'media_requirement' => 'video',
        ])->assertOk();

        $this->assertNotNull($master->fresh()->rules_customized_at);
        $this->assertSame('none', $existingItem->fresh()->media_requirement);

        $newItem = $this->workSheet($this->createJob())->items()->where('checklist_item_id', $master->id)->firstOrFail();
        $this->assertSame('video', $newItem->media_requirement);

        // Re-seeding keeps the owner's edit
        $this->seed(JobSheetTemplatesSeeder::class);
        $this->assertSame('video', $master->fresh()->media_requirement);
    }

    public function test_advisors_cannot_edit_masters(): void
    {
        $item = \App\Modules\Workshop\Models\ChecklistItem::firstOrFail();

        $this->as($this->advisor)->putJson("/api/v1/workshop/job-sheet-masters/sections/{$item->section_id}/items/{$item->id}", [
            'na_allowed' => true,
        ])->assertForbidden();
    }
}
