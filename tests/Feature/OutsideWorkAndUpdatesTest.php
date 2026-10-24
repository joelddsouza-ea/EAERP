<?php

namespace Tests\Feature;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobOutsideWork;
use App\Modules\Workshop\Models\PackagePricing;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\ServicePartner;
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
 * Owner feedback 2026-10-24: trial drive and washing on the technician's sheet, outside partners
 * (washing, wheel alignment) with their own login, staff "Send update" through the advisor,
 * and the customer's progress list switched off per job.
 */
class OutsideWorkAndUpdatesTest extends TestCase
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

    private function createJob(array $extra = [], ?string $plan = 'ea-refresh'): JobCard
    {
        $packageId = $plan ? ServicePackage::where('slug', $plan)->value('id') : null;
        $oilTypeId = $packageId ? PackagePricing::where('package_id', $packageId)
            ->where('category_id', Vehicle::find($this->vehicleId)->category_id)->value('oil_type_id') : null;

        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', array_filter([
            'km_reading' => 12000,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'package_id' => $packageId,
            'oil_type_id' => $oilTypeId,
            'service_type' => $plan ? 'scheduled' : 'general_repair',
        ]) + $extra)->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    private function jobInProgress(): JobCard
    {
        $job = $this->createJob();
        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $this->assertSame('work_in_progress', $job->fresh()->status);

        return $job->fresh();
    }

    private function workSheet(JobCard $job): JobCardChecklist
    {
        return JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
    }

    private function itemUrl(JobCard $job, JobCardChecklistItem $item, string $suffix = ''): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$item->job_card_checklist_id}/items/{$item->id}{$suffix}";
    }

    private function complete(JobCard $job, ?string $section = null): void
    {
        $items = $this->workSheet($job)->items()->when($section, fn ($q) => $q->where('section_name', $section))->get();
        foreach ($items as $item) {
            if ($item->media_requirement !== 'none') {
                $this->as($this->tech)->post($this->itemUrl($job, $item, '/media'), ['file' => UploadedFile::fake()->image('p.jpg', 200, 150)])->assertCreated();
            }
            $this->as($this->tech)->patchJson($this->itemUrl($job, $item), ['status' => 'done'])->assertOk();
        }
    }

    private function partner(string $kind, string $name, ?string $phone = null): ServicePartner
    {
        $id = $this->as($this->owner)->postJson('/api/v1/workshop/service-partners', ['name' => $name, 'kind' => $kind, 'phone' => '98200' . random_int(10000, 99999)])
            ->assertCreated()->json('data.id');
        if ($phone) {
            $this->as($this->owner)->postJson("/api/v1/workshop/service-partners/{$id}/login", ['phone' => $phone, 'password' => 'secret123'])->assertOk();
        }

        return ServicePartner::findOrFail($id);
    }

    // ---------------------------------------------------------------- tests

    public function test_a_job_without_a_wash_on_its_sheets_gets_the_trial_and_washing_sheet(): void
    {
        $job = $this->createJob([], null);

        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'post_service')->firstOrFail();
        $sections = $sheet->items()->orderBy('sort_order')->pluck('section_name')->unique()->values()->all();
        $this->assertSame(['Final Checks', 'Washing & Cleaning'], $sections);
        $this->assertSame('washing', $sheet->items()->where('section_name', 'Washing & Cleaning')->value('outside_kind'));
    }

    public function test_washing_partner_gets_the_car_uploads_and_the_job_waits_for_it(): void
    {
        $washer = $this->partner(ServicePartner::KIND_WASHING, 'Sparkle Wash', '9811100001');
        $other = $this->partner(ServicePartner::KIND_WASHING, 'Foam Bros', '9811100002');
        $partnerUser = User::where('phone', '9811100001')->firstOrFail();
        $this->assertTrue($partnerUser->hasRole(Role::SERVICE_PARTNER));

        $job = $this->jobInProgress();
        $base = "/api/v1/workshop/job-cards/{$job->id}/outside-works";

        // The technician sends the car after the trial drive, choosing the partner
        $this->as($this->tech)->postJson($base, ['kind' => 'washing', 'service_partner_id' => 999])->assertStatus(422);
        $work = $this->as($this->tech)->postJson($base, ['kind' => 'washing', 'service_partner_id' => $washer->id, 'note' => 'Full wash'])
            ->assertCreated()->json('data');
        $this->assertSame('sent', $work['status']);
        $this->assertStringStartsWith('https://wa.me/91', $work['partner_whatsapp_url']);
        $this->as($this->tech)->postJson($base, ['kind' => 'washing', 'service_partner_id' => $other->id])->assertStatus(422);
        $this->assertSame(1, $partnerUser->notifications()->where('data->kind', 'outside_work_sent')->count());

        // The partner sees only their own cars, never the customer; no access to job cards
        Sanctum::actingAs($partnerUser);
        $list = $this->withHeader('X-Branch-Id', '')->getJson('/api/v1/partner/works')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('MH01TEST', $list[0]['job']['registration_no']);
        $this->assertArrayNotHasKey('customer', $list[0]['job']);
        $this->assertNull($list[0]['partner']['phone']);
        $this->getJson("/api/v1/workshop/job-cards/{$job->id}")->assertForbidden();
        $this->postJson("/api/v1/partner/works/{$work['id']}/done")->assertStatus(422);
        $this->post("/api/v1/partner/works/{$work['id']}/media", ['file' => UploadedFile::fake()->image('wash.jpg', 200, 150)], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/v1/partner/works/{$work['id']}/done", ['note' => 'Washed'])->assertOk()->assertJsonPath('data.status', 'done');
        Sanctum::actingAs(User::where('phone', '9811100002')->firstOrFail());
        $this->getJson("/api/v1/partner/works/{$work['id']}")->assertNotFound();
        $this->assertSame(1, $this->tech->notifications()->where('data->kind', 'outside_work_done')->count());

        // The technician can't submit until the car is back
        $this->complete($job);
        $submit = "/api/v1/workshop/job-cards/{$job->id}/job-sheets/{$this->workSheet($job)->id}/submit";
        $this->as($this->tech)->postJson($submit)->assertStatus(422);
        $this->as($this->tech)->postJson("{$base}/{$work['id']}/returned")->assertOk()->assertJsonPath('data.status', 'returned');
        $this->as($this->tech)->postJson($submit)->assertOk();
        $this->assertSame('qc_pending', $job->fresh()->status);

        // The washer's photos are in the customer's gallery
        $token = app(\App\Modules\Workshop\Services\CustomerLinkService::class)->ensure($job->fresh(), $this->advisor)->token;
        $gallery = collect($this->getJson("/api/v1/public/j/{$token}")->assertOk()->json('data.gallery'));
        $this->assertSame('Washing', $gallery->firstWhere('key', 'outside')['groups'][0]['title']);
    }

    public function test_wheel_alignment_is_ticked_by_the_advisor_with_a_chosen_partner(): void
    {
        $this->partner(ServicePartner::KIND_WHEEL_ALIGNMENT, 'Align One');
        $second = $this->partner(ServicePartner::KIND_WHEEL_ALIGNMENT, 'Align Two', '9811100003');
        $washer = $this->partner(ServicePartner::KIND_WASHING, 'Sparkle Wash');

        $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $this->customerId, 'vehicle_id' => $this->vehicleId, 'service_type' => 'general_repair',
            'km_reading' => 12000, 'fuel_level' => 'half',
            'wheel_alignment_partner_id' => $washer->id,
        ])->assertStatus(422)->assertJsonValidationErrors('wheel_alignment_partner_id');

        $job = $this->createJob(['wheel_alignment_partner_id' => $second->id]);
        $work = JobOutsideWork::where('job_card_id', $job->id)->sole();
        $this->assertSame('wheel_alignment', $work->kind);
        $this->assertSame($second->id, $work->service_partner_id);
        $this->assertSame(1, User::where('phone', '9811100003')->firstOrFail()->notifications()->count());

        $this->as($this->advisor)->getJson("/api/v1/workshop/job-cards/{$job->id}/outside-works")->assertOk()
            ->assertJsonCount(2, 'data.partners.wheel_alignment');
        // Advisors can't manage the partner master
        $this->as($this->advisor)->postJson('/api/v1/workshop/service-partners', ['name' => 'X', 'kind' => 'washing'])->assertForbidden();
    }

    public function test_send_update_goes_to_the_advisor_who_sends_it(): void
    {
        $job = $this->jobInProgress();
        $job->update(['service_advisor_id' => $this->advisor->id]);
        $sheet = $this->workSheet($job);
        $url = "/api/v1/workshop/job-cards/{$job->id}/customer-updates";

        // Only a finished section
        $this->as($this->tech)->postJson($url, ['kind' => 'section', 'sheet_id' => $sheet->id, 'section' => 'Final Checks'])->assertStatus(422);
        $this->complete($job, 'Final Checks');
        $res = $this->as($this->tech)->postJson($url, ['kind' => 'section', 'sheet_id' => $sheet->id, 'section' => 'Final Checks'])
            ->assertOk()->assertJsonPath('data.to_advisor', true)->json('data.message');
        $this->assertStringContainsString('Final Checks is done', $res['body']);

        $alert = $this->advisor->notifications()->where('data->kind', 'customer_update')->sole();
        $this->assertSame($res['id'], $alert->data['message_id']);
        $this->assertStringStartsWith('https://wa.me/', $alert->data['share_url']);

        // The technician can't send it; the advisor does, once
        $this->as($this->tech)->postJson("/api/v1/workshop/customer-updates/{$res['id']}/sent")->assertForbidden();
        $this->as($this->advisor)->postJson("/api/v1/workshop/customer-updates/{$res['id']}/sent")->assertOk();
        $this->assertSame(CustomerMessage::SENT, CustomerMessage::find($res['id'])->status);
        $this->as($this->tech)->postJson($url, ['kind' => 'section', 'sheet_id' => $sheet->id, 'section' => 'Final Checks'])->assertStatus(422);

        // An advisor asking gets the message ready for themselves (no bell)
        $this->as($this->advisor)->postJson($url, ['kind' => 'stage'])->assertOk()->assertJsonPath('data.to_advisor', false);
    }

    public function test_progress_list_can_be_switched_off_per_job(): void
    {
        $job = $this->createJob(['show_customer_timeline' => false]);
        $token = app(\App\Modules\Workshop\Services\CustomerLinkService::class)->ensure($job, $this->advisor)->token;
        $this->getJson("/api/v1/public/j/{$token}")->assertOk()->assertJsonPath('data.job.timeline', null);

        $this->as($this->advisor)->putJson("/api/v1/workshop/job-cards/{$job->id}", ['show_customer_timeline' => true])->assertOk();
        $this->assertNotEmpty($this->getJson("/api/v1/public/j/{$token}")->json('data.job.timeline'));
    }
}
