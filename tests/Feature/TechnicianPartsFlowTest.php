<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
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
 * The technician asks for parts from the job sheet ("Submit to SA for Parts"), the
 * advisor forwards them to the coordinator, parts are priced, approved, received,
 * and the technician submits the final sheet. Advisors only view technician sheets.
 */
class TechnicianPartsFlowTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
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
        $this->advisor = User::where('email', 'advisor@expressautocare.com')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.com')->firstOrFail();
        $this->coordinator = User::where('email', 'parts@expressautocare.com')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Flow Test', 'phone' => '9000000077']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH03FLOW',
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ]);

        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 12000,
            'fuel_level' => 'half',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_type' => 'scheduled',
            'package_id' => ServicePackage::where('slug', 'ea-refresh')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
        ])->assertCreated()->json('data.id');

        $this->job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $this->job->update(['service_advisor_id' => $this->advisor->id]);
        $this->job->update(['km_reading' => 30000, 'fuel_level' => 'half', 'technician_id' => $this->tech->id]);
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

    private function workSheet(): JobCardChecklist
    {
        return JobCardChecklist::where('job_card_id', $this->job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
    }

    private function kinds(User $user): array
    {
        return $user->notifications()->get()->pluck('data.kind')->all();
    }

    public function test_advisors_and_managers_only_view_technician_sheets(): void
    {
        $sheet = $this->workSheet();
        $item = $sheet->items()->where('media_requirement', 'none')->firstOrFail();
        $itemUrl = $this->url("/job-sheets/{$sheet->id}/items/{$item->id}");

        $this->as($this->advisor)->getJson($this->url("/job-sheets/{$sheet->id}"))->assertOk();
        // The job sheet screen decides "view only" from these
        $this->as($this->tech)->getJson($this->url())
            ->assertJsonPath('data.technician_id', $this->tech->id)
            ->assertJsonPath('data.service_advisor_id', $this->advisor->id);
        $this->as($this->advisor)->patchJson($itemUrl, ['status' => 'done'])->assertForbidden();
        $this->as($this->owner)->patchJson($itemUrl, ['status' => 'done'])->assertForbidden();
        $this->as($this->tech)->patchJson($itemUrl, ['status' => 'done'])->assertOk();

        // Additional jobs and observations are the technician's
        $this->as($this->advisor)->postJson($this->url('/additional-jobs'), ['description' => 'x'])->assertForbidden();
        $this->as($this->advisor)->postJson($this->url('/observations'), ['text' => 'x'])->assertForbidden();
        $obsId = $this->as($this->tech)->postJson($this->url('/observations'), ['text' => 'Rear tyres worn'])->assertCreated()->json('data.id');

        // …but the advisor still decides what the customer sees
        $this->as($this->advisor)->patchJson($this->url("/observations/{$obsId}"), ['customer_visible' => true])->assertOk();
        $this->as($this->advisor)->patchJson($this->url("/observations/{$obsId}"), ['text' => 'changed'])->assertForbidden();

        // Customer complaints are still logged by the advisor
        $this->as($this->advisor)->postJson($this->url('/issues'), ['description' => 'Squeal when braking'])->assertCreated();

        // A technician can't skip the advisor and go straight to the coordinator
        $this->as($this->tech)->postJson($this->url('/part-requests'), ['parts' => [['part_name' => 'x']]])->assertForbidden();
    }

    public function test_parts_from_the_job_sheet_through_to_the_final_submission(): void
    {
        // Technician: an additional job with the parts it needs (drafts)
        $jobId = $this->as($this->tech)->postJson($this->url('/additional-jobs'), ['description' => 'Replace front brake pads'])
            ->assertCreated()->json('data.id');
        $padsId = $this->as($this->tech)->postJson($this->url("/additional-jobs/{$jobId}/parts"), [
            'part_name' => 'Front brake pad set', 'part_number' => 'BP-77', 'quantity' => 1,
        ])->assertCreated()->json('data.id');
        $clipId = $this->as($this->tech)->postJson($this->url("/additional-jobs/{$jobId}/parts"), ['part_name' => 'Pad clip'])
            ->assertCreated()->json('data.id');
        $this->as($this->tech)->deleteJson($this->url("/part-requests/{$clipId}"))->assertOk();

        $this->as($this->advisor)->postJson($this->url("/additional-jobs/{$jobId}/parts"), ['part_name' => 'x'])->assertForbidden();
        $this->assertCount(0, $this->as($this->advisor)->getJson($this->url('/part-requests'))->json('data')); // drafts hidden

        // "Submit to SA for Parts" with the sheet only partly done
        $this->as($this->tech)->postJson($this->url('/parts-submission'))->assertOk()->assertJsonPath('data.submitted', 1);
        $this->as($this->tech)->postJson($this->url('/parts-submission'))->assertStatus(422); // nothing new to send
        $this->assertContains('technician_needs_parts', $this->kinds($this->advisor));
        $this->assertSame(SparePartRequest::PROPOSED, SparePartRequest::find($padsId)->status);
        $this->as($this->tech)->deleteJson($this->url("/part-requests/{$padsId}"))->assertStatus(422);

        $list = $this->as($this->advisor)->getJson($this->url('/part-requests'))->json('data');
        $this->assertSame('Replace front brake pads', $list[0]['additional_job']);

        // Advisor forwards to the coordinator
        $this->as($this->advisor)->postJson($this->url('/part-requests/forward'), ['ids' => [$padsId]])->assertOk();
        $this->assertSame(SparePartRequest::REQUESTED, SparePartRequest::find($padsId)->status);
        $this->assertContains('parts_price_requested', $this->kinds($this->coordinator));

        // Coordinator prices it; advisor adds it to the estimate (linked to the job line) and sends
        $quoteId = $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$padsId}/quotes", [
            'supplier_name' => 'Brake House', 'unit_cost' => 900, 'gst_mode' => 'incl',
        ])->assertCreated()->json('data.id');
        $this->as($this->coordinator)->postJson('/api/v1/workshop/parts-desk/submit', ['selections' => [$padsId => $quoteId]])->assertOk();

        $estimate = $this->as($this->advisor)->postJson($this->url('/estimates'))->assertOk()->json('data');
        $this->as($this->advisor)->postJson($this->url("/estimates/{$estimate['id']}/parts"), ['spare_part_request_id' => $padsId])->assertCreated();
        $line = JobCardEstimateLine::where('spare_part_request_id', $padsId)->firstOrFail();
        $this->assertSame($jobId, $line->job_card_additional_job_id);
        $this->as($this->advisor)->postJson($this->url("/estimates/{$estimate['id']}/send"))->assertOk();

        $token = CustomerLink::where('job_card_id', $this->job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => [['line_key' => $line->line_key, 'decision' => 'approved']],
        ])->assertOk();

        // Part arrives: the coordinator marks it received, the technician is told
        $this->as($this->tech)->postJson("/api/v1/workshop/parts-desk/requests/{$padsId}/received")->assertForbidden();
        $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$padsId}/received")->assertOk();
        $this->assertSame(SparePartRequest::RECEIVED, SparePartRequest::find($padsId)->status);
        $this->assertContains('parts_received', $this->kinds($this->tech));

        // Technician finishes the sheet and submits it; the advisor is notified
        $sheet = $this->workSheet();
        foreach ($sheet->items as $item) {
            if ($item->media_requirement !== 'none') {
                $this->as($this->tech)->post($this->url("/job-sheets/{$sheet->id}/items/{$item->id}/media"), [
                    'file' => UploadedFile::fake()->image('p.jpg', 200, 150),
                ])->assertCreated();
            }
            $this->as($this->tech)->patchJson($this->url("/job-sheets/{$sheet->id}/items/{$item->id}"), ['status' => 'done'])->assertOk();
        }
        $this->as($this->advisor)->postJson($this->url("/job-sheets/{$sheet->id}/submit"))->assertForbidden();
        $this->as($this->tech)->postJson($this->url("/job-sheets/{$sheet->id}/submit"))->assertOk();
        $this->assertContains('job_sheet_submitted', $this->kinds($this->advisor));
    }
}
