<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Bay;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;
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
 * Several technicians and bay moves, N/A without media, "need attention" clearing,
 * the pre-delivery QC spot checks, odometer/fuel at creation, and the car class master.
 */
class AssignmentsQcCarClassesTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private User $tech2;
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
        $this->tech2 = User::where('email', 'tech2@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Assign Test', 'phone' => '9000000055']);
        $this->customerId = $customer->id;
        $this->vehicleId = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH05ASGN',
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

    private function newJob(): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'service_type' => 'scheduled',
            'package_id' => ServicePackage::where('slug', 'ea-refresh')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'km_reading' => 20000,
            'fuel_level' => 'half',
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    private function jobAtWork(): JobCard
    {
        $job = $this->newJob();
        $this->as($this->advisor)->postJson($this->url($job, '/technicians'), ['user_id' => $this->tech->id])->assertOk();
        $this->completeIntake($job, $this->advisor->id);
        // Intake complete + technician assigned: work starts by itself
        $this->assertSame('work_in_progress', $job->fresh()->status);

        return $job->fresh();
    }

    private function sheet(JobCard $job, string $kind): JobCardChecklist
    {
        return JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', $kind)->firstOrFail();
    }

    public function test_odometer_and_fuel_are_needed_to_create_a_job_card(): void
    {
        $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'service_type' => 'ac_service',
        ])->assertStatus(422)->assertJsonValidationErrors(['km_reading', 'fuel_level']);
    }

    public function test_several_technicians_and_bay_moves(): void
    {
        $job = $this->jobAtWork();
        $sheet = $this->sheet($job, 'service_activities');
        $item = $sheet->items()->where('media_requirement', 'none')->firstOrFail();
        $itemUrl = $this->url($job, "/job-sheets/{$sheet->id}/items/{$item->id}");

        // The candidates come with the assignments (advisors need no user-admin access)
        $data = $this->as($this->advisor)->getJson($this->url($job, '/assignments'))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->tech->id, $this->tech2->id], array_column($data['candidates']['technicians'], 'id'));
        $this->assertSame([[$this->tech->id, true]], array_map(fn ($t) => [$t['id'], $t['is_lead']], $data['technicians']));

        // A second technician on the same job can fill the sheet too
        $this->as($this->tech2)->patchJson($itemUrl, ['status' => 'done'])->assertForbidden();
        $this->as($this->advisor)->postJson($this->url($job, '/technicians'), ['user_id' => $this->tech2->id])->assertOk()
            ->assertJsonPath('data.technicians.1.is_lead', false);
        $this->as($this->tech2)->patchJson($itemUrl, ['status' => 'done'])->assertOk();
        $this->assertContains($job->id, array_column(
            $this->as($this->tech2)->getJson("/api/v1/workshop/job-cards?technician_id={$this->tech2->id}")->json('data'), 'id'
        ));
        $this->as($this->advisor)->postJson($this->url($job, '/technicians'), ['user_id' => $this->advisor->id])->assertStatus(422);

        // The lead leaves: the other technician becomes lead
        $this->as($this->advisor)->deleteJson($this->url($job, "/technicians/{$this->tech->id}"))->assertOk();
        $this->assertSame($this->tech2->id, $job->fresh()->technician_id);

        // Bay, then moved to the wash bay: the history is kept
        $service = Bay::create(['branch_id' => $this->branchId, 'name' => 'Bay 1', 'code' => 'B1', 'type' => 'general', 'is_active' => true]);
        $wash = Bay::create(['branch_id' => $this->branchId, 'name' => 'Wash Bay', 'code' => 'W1', 'type' => 'detailing', 'is_active' => true]);
        $this->as($this->advisor)->postJson($this->url($job, '/bay'), ['bay_id' => $service->id])->assertOk();
        $moved = $this->as($this->advisor)->postJson($this->url($job, '/bay'), ['bay_id' => $wash->id, 'notes' => 'For washing'])
            ->assertOk()->json('data');
        $this->assertSame('Wash Bay', $moved['bay']['name']);
        $this->assertSame(['Bay 1', 'For washing'], [$moved['bay_moves'][0]['from'], $moved['bay_moves'][0]['notes']]);
        $this->assertCount(2, $moved['bay_moves']);
    }

    public function test_na_needs_no_photo_and_need_attention_clears_with_the_line(): void
    {
        $job = $this->jobAtWork();
        $sheet = $this->sheet($job, 'service_activities');
        $mediaItem = $sheet->items()->where('media_requirement', '!=', 'none')->where('na_allowed', true)->first()
            ?? tap($sheet->items()->where('media_requirement', '!=', 'none')->firstOrFail())->update(['na_allowed' => true]);
        $url = fn ($i) => $this->url($job, "/job-sheets/{$sheet->id}/items/{$i->id}");

        $response = $this->as($this->tech)->patchJson($url($mediaItem), ['status' => 'not_applicable'])->assertOk()->json('data');
        $this->assertNull($response['item']['media_shortfall']);
        $this->assertSame(1, $response['progress']['resolved']);

        // ⚠ counts while its Additional Issues line is open
        $flag = $sheet->items()->where('media_requirement', 'none')->firstOrFail();
        $progress = $this->as($this->tech)->patchJson($url($flag), ['needs_attention' => true, 'remarks' => 'Hose cracked'])
            ->assertOk()->json('data.progress');
        $this->assertSame(1, $progress['needs_attention']);

        $line = collect($this->as($this->tech)->getJson($this->url($job, '/issues'))->json('data'))->firstWhere('source', 'activity');
        $this->as($this->tech)->post($this->url($job, "/issues/{$line['id']}/media"), ['file' => UploadedFile::fake()->image('h.jpg')])->assertCreated();
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$line['id']}"), ['status' => 'resolved'])->assertOk();

        $sheetData = $this->as($this->tech)->getJson($this->url($job, "/job-sheets/{$sheet->id}"))->json('data');
        $this->assertSame(0, $sheetData['progress']['needs_attention']);
    }

    public function test_pre_delivery_qc_spot_checks_need_a_photo_within_thirty_seconds(): void
    {
        $job = $this->jobAtWork();
        // Final QC comes after the wash
        $job->update(['status' => 'qc_pending']);
        $pre = $this->sheet($job, 'pre_delivery');
        $flagged = $pre->items()->where('qc_evidence_required', true)->get();
        $this->assertCount(3, $flagged);

        // Hidden until marked
        $listed = $this->as($this->advisor)->getJson($this->url($job, '/pre-delivery'))->json('data.sections');
        $this->assertFalse(collect($listed)->flatMap(fn ($s) => $s['items'])->contains(fn ($i) => $i['qc_evidence'] !== null));

        $qc = $flagged->first();
        $url = $this->url($job, "/job-sheets/{$pre->id}/items/{$qc->id}");
        $answer = $this->as($this->advisor)->patchJson($url, ['value' => $qc->expected_answer])->assertOk()->json('data.item');
        $this->assertTrue($answer['qc_evidence']['required']);
        // 30 seconds to shoot it
        $this->assertSame(30, (int) abs(\Illuminate\Support\Carbon::parse($answer['qc_evidence']['deadline'])
            ->diffInSeconds(\Illuminate\Support\Carbon::parse($answer['qc_evidence']['marked_at']))));

        // A picture taken 45 s later (or from the gallery) is refused and the answer cleared
        $this->as($this->advisor)->post("{$url}/media", [
            'file' => UploadedFile::fake()->image('late.jpg'),
            'captured_at' => now()->addSeconds(45)->toIso8601String(),
        ])->assertStatus(422);
        $this->assertNull(JobCardChecklistItem::find($qc->id)->value);

        // Marked again and shot straight away
        $this->as($this->advisor)->patchJson($url, ['value' => $qc->expected_answer])->assertOk();
        $this->as($this->advisor)->post("{$url}/media", [
            'file' => UploadedFile::fake()->image('qc.jpg'),
            'captured_at' => now()->toIso8601String(),
        ])->assertCreated();

        // The other spot checks still block the checklist until their evidence is taken
        foreach ($pre->items as $item) {
            if ($item->id !== $qc->id) {
                $this->as($this->advisor)->patchJson($this->url($job, "/job-sheets/{$pre->id}/items/{$item->id}"), ['value' => $item->expected_answer])->assertOk();
            }
        }
        $problems = $this->as($this->advisor)->postJson($this->url($job, "/job-sheets/{$pre->id}/submit"))
            ->assertStatus(422)->json('errors.items');
        $this->assertCount(2, array_filter($problems, fn ($p) => $p['code'] === 'qc_evidence_missing'));
    }

    public function test_car_classes_and_models_are_a_master(): void
    {
        $base = '/api/v1/workshop/pricing-masters';
        $this->as($this->advisor)->getJson("{$base}/car-classes")->assertOk();
        $this->as($this->advisor)->postJson("{$base}/car-classes", ['name' => 'Vintage'])->assertForbidden();

        $class = $this->as($this->owner)->postJson("{$base}/car-classes", ['name' => 'Electric SUV'])->assertCreated()->json('data');
        $make = $this->as($this->owner)->postJson("{$base}/car-makes", ['name' => 'BYD'])->assertCreated()->json('data');
        $model = $this->as($this->owner)->postJson("{$base}/car-models", ['make_id' => $make['id'], 'name' => 'Atto 3', 'class_id' => $class['id']])
            ->assertCreated()->json('data');
        $this->assertSame($class['id'], VehicleModel::find($model['id'])->default_category_id);

        // Reclassify an existing model; re-seeding keeps it
        $swift = VehicleModel::where('name', 'Swift')->first() ?? VehicleModel::create([
            'make_id' => VehicleMake::firstOrCreate(['name' => 'Maruti Suzuki'])->id, 'name' => 'Swift',
        ]);
        $hatch = VehicleCategory::where('slug', 'hatchback')->value('id');
        $this->as($this->owner)->putJson("{$base}/car-models/{$swift->id}", ['class_id' => $hatch])->assertOk();
        $this->assertSame($hatch, $swift->fresh()->default_category_id);

        $this->as($this->owner)->putJson("{$base}/car-classes/{$class['id']}", ['name' => 'EV SUV'])->assertOk();
        $this->seed(VehicleCategoriesSeeder::class);
        $this->assertSame('EV SUV', VehicleCategory::find($class['id'])->name);

        $data = $this->as($this->owner)->getJson("{$base}/car-classes")->json('data');
        $this->assertFalse(collect($data['classes'])->firstWhere('id', $class['id'])['has_prices']);
    }
}
