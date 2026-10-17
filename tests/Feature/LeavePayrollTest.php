<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Accounts\Services\AccountsReportService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\IncentiveRule;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6: leave and October 2026 payroll (31 days; Sundays 4, 11, 18, 25 are weekly offs).
 */
class LeavePayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $backoffice;
    private User $tech;
    private User $tech2;
    private User $advisor;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);

        $this->owner = User::where('email', 'admin@expressautocare.com')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.com')->firstOrFail();
        $this->backoffice = User::where('email', 'backoffice@expressautocare.com')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.com')->firstOrFail();
        $this->tech2 = User::where('email', 'tech2@expressautocare.com')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.com')->firstOrFail();
        $this->branch = $this->tech->branches()->firstOrFail();

        EmployeeProfile::create([
            'user_id' => $this->tech->id,
            'branch_id' => $this->branch->id,
            'gender' => 'male',
            'date_joined' => '2025-01-01',
            'monthly_salary' => 31000,
            'basic_salary' => 15000,
            'ot_hourly_rate' => 150,
            'pt_enabled' => true,
            'pf_enabled' => true,
        ]);

        // A paid holiday on Friday 2 October
        Holiday::create(['date' => '2026-10-02', 'name' => 'Gandhi Jayanti']);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branch->id);
    }

    private function punchDay(User $user, string $date, string $in = '09:30', string $out = '19:00'): void
    {
        foreach ([['in', $in], ['out', $out]] as [$type, $time]) {
            AttendancePunch::create([
                'user_id' => $user->id, 'branch_id' => $this->branch->id, 'work_date' => $date, 'type' => $type,
                'punched_at' => Carbon::parse("{$date} {$time}"), 'source' => 'selfie', 'created_by' => $user->id,
            ]);
        }
        app(AttendanceService::class)->rebuildDay($user->id, $date);
    }

    /**
     * October for the technician: CL on the 7th (approved), absent on the 8th, half day on
     * the 9th, 2 h late out on the 5th, worked Sunday the 11th, every other working day full.
     */
    private function october(): void
    {
        $this->travelTo('2026-10-06 10:00');
        $leaveId = $this->as($this->tech)->postJson('/api/v1/hr/me/leave', [
            'leave_type_id' => LeaveType::where('code', 'CL')->value('id'),
            'from_date' => '2026-10-07',
            'reason' => 'Family function',
        ])->assertCreated()->assertJsonPath('data.days', 1)->assertJsonPath('data.unpaid_days', 0)->json('data.id');

        // The manager is told and approves
        $this->assertTrue($this->manager->notifications()->where('data->kind', 'leave_requested')->exists());
        $this->as($this->tech)->postJson("/api/v1/hr/leave-requests/{$leaveId}/decide", ['approve' => true])->assertForbidden();
        $this->as($this->manager)->postJson("/api/v1/hr/leave-requests/{$leaveId}/decide", ['approve' => true])->assertOk();

        $this->travelTo('2026-11-03 10:00');
        foreach (CarbonPeriod::create('2026-10-01', '2026-10-31') as $d) {
            $date = $d->toDateString();
            if ($d->isSunday() || in_array($date, ['2026-10-02', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-05'], true)) {
                continue;
            }
            $this->punchDay($this->tech, $date);
        }
        $this->punchDay($this->tech, '2026-10-05', '09:30', '21:00'); // 1 h OT
        $this->punchDay($this->tech, '2026-10-09', '09:30', '12:00'); // half day
        $this->punchDay($this->tech, '2026-10-11'); // Sunday worked
    }

    private function lines(array $slip): array
    {
        return collect($slip['lines'])->mapWithKeys(fn ($l) => [$l['code'] => $l['amount']])->all();
    }

    public function test_monthly_payroll_from_attendance_leave_ot_statutory_and_recoveries(): void
    {
        $this->october();

        // An advance (2,000 a month), customer money still held, a penalty
        SalaryAdvance::create(['user_id' => $this->tech->id, 'branch_id' => $this->branch->id, 'amount' => 5000, 'given_on' => '2026-10-10', 'method' => 'cash', 'recover_per_month' => 2000, 'status' => 'open']);
        StaffCustodyEntry::create(['user_id' => $this->tech->id, 'branch_id' => $this->branch->id, 'entry_date' => '2026-10-20', 'amount' => 1200, 'kind' => 'collected']);
        $this->as($this->backoffice)->postJson('/api/v1/hr/adjustments', ['user_id' => $this->tech->id, 'period' => '2026-10', 'kind' => 'penalty', 'amount' => 300, 'note' => 'Late 3 times'])->assertCreated();

        // Technicians can't see payroll
        $this->as($this->tech)->postJson('/api/v1/hr/payroll-runs', ['period' => '2026-10'])->assertForbidden();

        $run = $this->as($this->backoffice)->postJson('/api/v1/hr/payroll-runs', ['period' => '2026-10'])->assertCreated()->json('data');
        $slip = collect($run['payslips'])->firstWhere('user_id', $this->tech->id);

        // 31 days − 1 absent − ½ half day = 29.5 paid (holiday, Sundays and the CL are paid)
        $this->assertEquals(29.5, $slip['paid_days']);
        $this->assertEquals(1.5, $slip['lop_days']);
        $this->assertEquals(1, $slip['off_day_works']);
        $this->assertEquals(1.0, $slip['ot_hours']);

        $l = $this->lines($slip);
        $this->assertEquals(29500, $l['salary']);
        $this->assertEquals(150, $l['ot']);
        $this->assertEquals(500, $l['offday']);
        $this->assertEquals(30150, $slip['gross']);
        $this->assertEquals(200, $l['pt']);
        // PF 12% of the basic for the paid days: 15,000 × 29.5 / 31
        $this->assertEquals(1713, $l['pf']);
        $this->assertEquals(1713, $l['pf_employer']);
        $this->assertEquals(300, $l['penalty']);
        $this->assertEquals(2000, $l['advance']);
        $this->assertEquals(1200, $l['custody']);
        $this->assertEquals(5413, $slip['deductions']);
        $this->assertEquals(24737, $slip['net']);

        // Back office corrects a line with a reason; recalculation keeps it
        $otLine = collect($slip['lines'])->firstWhere('code', 'ot');
        $this->as($this->backoffice)->putJson("/api/v1/hr/payslip-lines/{$otLine['id']}", ['amount' => 300])->assertStatus(422);
        $this->as($this->backoffice)->putJson("/api/v1/hr/payslip-lines/{$otLine['id']}", ['amount' => 300, 'note' => 'Stayed for the Innova delivery'])->assertOk();
        $after = collect($this->as($this->backoffice)->postJson("/api/v1/hr/payroll-runs/{$run['id']}/calculate")->assertOk()->json('data.payslips'))->firstWhere('user_id', $this->tech->id);
        $this->assertEquals(300, $this->lines($after)['ot']);
        $this->assertEquals(24887, $after['net']);

        // Only the owner finalises
        $this->as($this->backoffice)->postJson("/api/v1/hr/payroll-runs/{$run['id']}/finalise")->assertForbidden();
        $this->as($this->owner)->postJson("/api/v1/hr/payroll-runs/{$run['id']}/finalise")->assertOk()->assertJsonPath('data.status', 'finalised');

        // Recoveries are booked; the month's attendance is locked
        $this->assertEquals(2000, SalaryAdvance::firstOrFail()->recovered);
        $this->assertEquals(0, StaffCustodyEntry::balanceFor($this->tech->id));
        $this->as($this->manager)->postJson("/api/v1/hr/attendance/users/{$this->tech->id}/punches", ['date' => '2026-10-08', 'type' => 'in', 'time' => '09:30', 'reason' => 'late fix'])
            ->assertStatus(422);

        // The salary cost is on the books, balanced
        $entry = JournalEntry::where('source_type', 'payslip')->firstOrFail();
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));

        // Paid → salary payable cleared from the bank
        $this->as($this->backoffice)->postJson("/api/v1/hr/payroll-runs/{$run['id']}/pay", ['method' => 'bank_transfer', 'reference' => 'NEFT123'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertTrue(JournalEntry::where('source_type', 'payslip_payment')->exists());
        $tb = app(AccountsReportService::class)->trialBalance('2026-11-30');
        $this->assertEqualsWithDelta($tb['total_debit'], $tb['total_credit'], 0.001);

        // The employee sees the payslip and can download it
        $mine = $this->as($this->tech)->getJson('/api/v1/hr/me/payslips')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->as($this->tech)->get("/api/v1/hr/payslips/{$mine[0]['id']}/pdf")->assertOk();
        $this->as($this->tech2)->get("/api/v1/hr/payslips/{$mine[0]['id']}/pdf")->assertNotFound();
    }

    public function test_statutory_switch_off_and_pt_slabs(): void
    {
        $this->october();
        WorkshopSettings::set('hr.statutory.enabled', false);

        $run = $this->as($this->backoffice)->postJson('/api/v1/hr/payroll-runs', ['period' => '2026-10'])->assertCreated()->json('data');
        $l = $this->lines(collect($run['payslips'])->firstWhere('user_id', $this->tech->id));
        $this->assertArrayNotHasKey('pt', $l);
        $this->assertArrayNotHasKey('pf', $l);

        $payroll = app(PayrollService::class);
        $this->assertEquals(0, $payroll->professionalTax(7000, 'male', 10));
        $this->assertEquals(175, $payroll->professionalTax(9000, 'male', 10));
        $this->assertEquals(200, $payroll->professionalTax(15000, 'male', 10));
        $this->assertEquals(300, $payroll->professionalTax(15000, 'male', 2));
        $this->assertEquals(0, $payroll->professionalTax(20000, 'female', 10));
        $this->assertEquals(200, $payroll->professionalTax(30000, 'female', 10));
    }

    public function test_leave_beyond_the_balance_is_unpaid_and_a_month_not_over_cannot_be_finalised(): void
    {
        $this->travelTo('2026-10-05 10:00');
        $sl = LeaveType::where('code', 'SL')->firstOrFail();
        $sl->update(['annual_quota' => 2]);

        // Mon 12 – Fri 16 October: 5 working days, 2 paid
        $res = $this->as($this->tech)->postJson('/api/v1/hr/me/leave', ['leave_type_id' => $sl->id, 'from_date' => '2026-10-12', 'to_date' => '2026-10-16'])
            ->assertCreated();
        $this->assertEquals(5, $res->json('data.days'));
        $this->assertEquals(3, $res->json('data.unpaid_days'));

        // Overlapping leave is refused
        $this->as($this->tech)->postJson('/api/v1/hr/me/leave', ['leave_type_id' => $sl->id, 'from_date' => '2026-10-14'])->assertStatus(422);

        $this->as($this->manager)->postJson('/api/v1/hr/leave-requests/' . $res->json('data.id') . '/decide', ['approve' => true])->assertOk();

        // Future days fill in as they pass (nightly close)
        $this->travelTo('2026-10-20 10:00');
        foreach (CarbonPeriod::create('2026-10-12', '2026-10-16') as $d) {
            $this->artisan('hr:close-attendance', ['date' => $d->toDateString()])->assertSuccessful();
        }
        $statuses = DB::table('attendance_days')->where('user_id', $this->tech->id)->whereBetween('date', ['2026-10-12', '2026-10-16'])->orderBy('date')->pluck('status')->all();
        $this->assertSame(['leave_paid', 'leave_paid', 'leave_unpaid', 'leave_unpaid', 'leave_unpaid'], $statuses);

        $run = PayrollRun::create(['period' => '2026-10', 'status' => 'draft']);
        $this->as($this->owner)->postJson("/api/v1/hr/payroll-runs/{$run->id}/finalise")->assertStatus(422);
    }

    public function test_incentive_rules_split_between_the_jobs_technicians(): void
    {
        EmployeeProfile::create(['user_id' => $this->tech2->id, 'branch_id' => $this->branch->id, 'monthly_salary' => 20000, 'attendance_required' => false, 'pt_enabled' => false]);
        EmployeeProfile::where('user_id', $this->tech->id)->update(['attendance_required' => false, 'pt_enabled' => false, 'pf_enabled' => false]);
        EmployeeProfile::create(['user_id' => $this->advisor->id, 'branch_id' => $this->branch->id, 'monthly_salary' => 25000, 'attendance_required' => false, 'pt_enabled' => false]);

        IncentiveRule::create(['name' => 'Technician 5% of labour', 'roles' => ['technician'], 'basis' => 'pct_labour', 'value' => 5, 'is_active' => true]);
        IncentiveRule::create(['name' => 'Advisor Rs 100 per job', 'roles' => ['service-advisor'], 'basis' => 'per_job_delivered', 'value' => 100, 'is_active' => true]);

        $customer = Customer::create(['name' => 'Rohan', 'phone' => '9000000002']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH04BB2222']);
        $jobId = DB::table('job_cards')->insertGetId([
            'branch_id' => $this->branch->id, 'job_number' => 'JC-I-1', 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'status' => 'delivered', 'service_type' => 'scheduled', 'technician_id' => $this->tech->id, 'service_advisor_id' => $this->advisor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('job_card_technicians')->insert(['job_card_id' => $jobId, 'user_id' => $this->tech2->id, 'is_lead' => false, 'created_at' => now(), 'updated_at' => now()]);

        $bill = Invoice::create([
            'branch_id' => $this->branch->id, 'job_card_id' => $jobId, 'customer_id' => $customer->id, 'kind' => 'tax',
            'invoice_number' => 'INV-TEST-1', 'invoice_date' => '2026-10-15', 'status' => 'issued',
        ]);
        // Labour 11,800 incl. GST = 10,000 taxable; parts are not counted
        $bill->items()->create(['type' => 'labor', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 11800, 'tax_rate' => 18, 'gst_inclusive' => true]);
        $bill->items()->create(['type' => 'part', 'description' => 'Brake pads', 'quantity' => 1, 'unit_price' => 5900, 'tax_rate' => 18, 'gst_inclusive' => true]);

        $this->travelTo('2026-11-02 10:00');
        $run = $this->as($this->backoffice)->postJson('/api/v1/hr/payroll-runs', ['period' => '2026-10'])->assertCreated()->json('data');
        $slips = collect($run['payslips'])->keyBy('user_id');

        $this->assertEquals(250, $this->lines($slips[$this->tech->id])['incentive']);
        $this->assertEquals(250, $this->lines($slips[$this->tech2->id])['incentive']);
        $this->assertEquals(100, $this->lines($slips[$this->advisor->id])['incentive']);
    }
}
