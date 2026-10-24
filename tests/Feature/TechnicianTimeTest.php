<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\JobOutsideWork;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workshop › Technician Time: time in Work In Progress, less outside-partner time,
 * inside shift hours only; each technician on the job gets its full working time.
 */
class TechnicianTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $advisor;
    private User $tech;
    private User $tech2;
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
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->tech2 = User::where('email', 'tech2@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    /**
     * A job with a made-up history: [status, 'Y-m-d H:i'] in order.
     */
    private function job(string $reg, array $history): JobCard
    {
        $customer = Customer::create(['name' => 'Time ' . $reg, 'phone' => '90000' . random_int(10000, 99999)]);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => $reg, 'category_id' => VehicleCategory::firstOrFail()->id, 'fuel_type' => 'petrol']);
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'service_type' => 'general_repair',
            'km_reading' => 12000, 'fuel_level' => 'half',
        ])->assertCreated()->json('data.id');
        $job = JobCard::withoutGlobalScopes()->findOrFail($id);

        JobCardStatusHistory::where('job_card_id', $job->id)->delete();
        foreach ($history as [$status, $at]) {
            JobCardStatusHistory::create(['job_card_id' => $job->id, 'to_status' => $status])->forceFill(['created_at' => $at])->save();
        }
        $started = collect($history)->firstWhere(0, 'work_in_progress')[1];
        $job->forceFill(['status' => end($history)[0], 'work_started_at' => $started, 'work_completed_at' => collect($history)->firstWhere(0, 'qc_pending')[1] ?? null, 'technician_id' => $this->tech->id])->save();
        $job->technicians()->sync([$this->tech->id => ['is_lead' => true], $this->tech2->id => ['is_lead' => false]]);

        return $job->fresh();
    }

    public function test_working_time_leaves_out_waits_outside_work_and_off_hours(): void
    {
        // Monday 19 Oct 2026, General shift 09:30–19:00 with a 30-minute break
        $job = $this->job('MH01TT01', [
            ['work_in_progress', '2026-10-19 10:00:00'],
            ['awaiting_parts', '2026-10-19 13:00:00'],
            ['work_in_progress', '2026-10-19 15:00:00'],
            ['qc_pending', '2026-10-19 18:00:00'],
        ]);
        $partner = ServicePartner::create(['name' => 'Sparkle Wash', 'kind' => 'washing', 'is_active' => true]);
        JobOutsideWork::create(['job_card_id' => $job->id, 'kind' => 'washing', 'service_partner_id' => $partner->id, 'status' => 'returned',
            'sent_at' => '2026-10-19 16:00:00', 'returned_at' => '2026-10-19 17:00:00']);
        // Sunday (weekly off): two hours of work that don't count
        $this->job('MH01TT02', [
            ['work_in_progress', '2026-10-18 10:00:00'],
            ['qc_pending', '2026-10-18 12:00:00'],
        ]);

        $this->as($this->advisor)->getJson('/api/v1/workshop/reports/technician-time?from=2026-10-18&to=2026-10-19')->assertForbidden();
        $data = $this->as($this->manager)->getJson('/api/v1/workshop/reports/technician-time?from=2026-10-18&to=2026-10-19')->assertOk()->json('data');

        $factor = (570 - 30) / 570;
        $row = collect($data['jobs'])->firstWhere('id', $job->id);
        $this->assertSame((int) round(360 * $factor), $row['gross_minutes']);      // 6 h in work
        $this->assertSame((int) round(120 * $factor), $row['parts_minutes']);      // 2 h waiting for parts
        $this->assertSame((int) round(60 * $factor), $row['outside_minutes']);     // 1 h at the washer
        $this->assertSame((int) round(300 * $factor), $row['working_minutes']);    // 5 h worked
        $this->assertCount(1, $data['jobs']);                                       // Sunday's job: nothing in working hours

        // Both technicians are credited with the full working time
        $techs = collect($data['technicians'])->keyBy('id');
        $this->assertSame((int) round(300 * $factor), $techs[$this->tech->id]['working_minutes']);
        $this->assertSame((int) round(300 * $factor), $techs[$this->tech2->id]['working_minutes']);

        // One technician only; CSV; the job card's own figure
        $only = $this->as($this->owner)->getJson("/api/v1/workshop/reports/technician-time?from=2026-10-18&to=2026-10-19&technician_id={$this->tech2->id}")->assertOk()->json('data.technicians');
        $this->assertSame([$this->tech2->id], array_column($only, 'id'));
        $this->as($this->owner)->get('/api/v1/workshop/reports/technician-time.csv?from=2026-10-18&to=2026-10-19')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->as($this->manager)->getJson("/api/v1/workshop/job-cards/{$job->id}/working-time")->assertOk()->assertJsonPath('data.working_minutes', (int) round(300 * $factor));
    }
}
