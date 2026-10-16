<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\ChecklistSection;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Services\CustomerDocumentService;
use App\Modules\Workshop\Services\JobSheetRuleEngine;
use App\Core\Models\Role;
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
 * Feedback round after auto stages & billing: technician assignment notifications,
 * the technician list for advisors, split payments, money paid beyond the bill refunded
 * or kept as credit, the discount line above the taxable value, and "photo and video" activities.
 */
class RoundSixFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $backoffice;
    private User $tech;
    private User $tech2;
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
        WorkflowStage::flushCache();

        $this->owner = User::where('email', 'admin@expressautocare.com')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.com')->firstOrFail();
        $this->backoffice = User::where('email', 'backoffice@expressautocare.com')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.com')->firstOrFail();
        $this->tech2 = User::where('email', 'tech2@expressautocare.com')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $customer = Customer::create(['name' => 'Round Six', 'phone' => '9000000066']);
        $this->customerId = $customer->id;
        $this->vehicleId = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => 'MH05RS6666',
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ])->id;
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function base(JobCard $job): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}";
    }

    private function newJob(array $extra = []): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 40100,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'package_id' => ServicePackage::where('slug', 'ea-360')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'service_type' => 'scheduled',
            ...$extra,
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    /** Plan + two approved labour jobs with Rs 100 off the extras: Rs 11,845, car ready for delivery */
    private function readyJob(): JobCard
    {
        $job = $this->newJob();
        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);

        $estimate = $this->as($this->advisor)->postJson($this->base($job) . '/estimates')->assertOk()->json('data');
        foreach ([['Throttle Body Service', 1770], ['Wheel Alignment', 600]] as [$desc, $rate]) {
            $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/lines", [
                'kind' => 'labor', 'description' => $desc, 'quantity' => 1, 'rate' => $rate,
            ])->assertCreated();
        }
        $this->as($this->advisor)->putJson($this->base($job) . "/estimates/{$estimate['id']}", ['extras_discount_flat' => 100])->assertOk();
        $sent = $this->as($this->advisor)->postJson($this->base($job) . "/estimates/{$estimate['id']}/send")->assertOk()->json('data');

        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => collect($sent['estimate']['additional_lines'])->map(fn ($l) => ['line_key' => $l['line_key'], 'decision' => 'approved'])->all(),
        ])->assertOk();

        $job->refresh()->update(['status' => 'ready_for_delivery']);

        return $job->fresh();
    }

    private function line(array $bill, string $startsWith): array
    {
        return collect($bill['lines'])->sortBy(fn ($l) => $l['section'] === 'plan')->first(fn ($l) => str_starts_with($l['description'], $startsWith));
    }

    private function alerts(User $user, string $kind)
    {
        return $user->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === $kind)->values();
    }

    // ------------------------------------------------------------------ tests

    public function test_technician_is_notified_when_put_on_a_job(): void
    {
        $job = $this->newJob();
        $url = $this->base($job);

        $this->as($this->advisor)->postJson("{$url}/technicians", ['user_id' => $this->tech->id])->assertOk();
        $alerts = $this->alerts($this->tech, 'job_assigned');
        $this->assertCount(1, $alerts);
        $this->assertSame("/job-cards/{$job->id}/sheet", $alerts[0]->data['link']);
        $this->assertStringContainsString($job->job_number, $alerts[0]->data['title']);
        $this->assertStringContainsString('MH05RS6666', $alerts[0]->data['body']);

        // A second technician is told too; making someone already on the job the lead is not news
        $this->as($this->advisor)->postJson("{$url}/technicians", ['user_id' => $this->tech2->id])->assertOk();
        $this->as($this->advisor)->postJson("{$url}/technicians", ['user_id' => $this->tech2->id, 'is_lead' => true])->assertOk();
        $this->assertCount(1, $this->alerts($this->tech2, 'job_assigned'));
        $this->assertCount(1, $this->alerts($this->tech, 'job_assigned'));

        // Choosing the technician on the job card form also notifies and records the assignment
        $other = $this->newJob(['technician_id' => $this->tech->id]);
        $this->assertSame($this->tech->id, $other->technician_id);
        $this->assertTrue($other->technicians()->whereKey($this->tech->id)->exists());
        $this->assertCount(2, $this->alerts($this->tech, 'job_assigned'));
    }

    public function test_advisor_loads_technicians_without_user_admin_permission(): void
    {
        $this->assertFalse($this->advisor->hasPermission('user-admin.users.view'));
        $this->as($this->advisor)->getJson('/api/v1/user-admin/users?role=technician')->assertForbidden();

        $names = collect($this->as($this->advisor)->getJson("/api/v1/workshop/technicians?branch_id={$this->branchId}")
            ->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($names->contains($this->tech->id));
        $this->assertTrue($names->contains($this->tech2->id));
        $this->assertFalse($names->contains($this->advisor->id));

        // The job card's own candidates list works for the advisor as well
        $job = $this->newJob();
        $candidates = collect($this->as($this->advisor)->getJson($this->base($job) . '/assignments')->assertOk()->json('data.candidates.technicians'));
        $this->assertTrue($candidates->pluck('id')->contains($this->tech->id));
    }

    public function test_user_admin_api_needs_the_user_admin_permissions(): void
    {
        $ownerRole = Role::where('slug', Role::OWNER)->firstOrFail();

        // A technician can neither read users nor make themselves owner
        $this->as($this->tech)->getJson('/api/v1/user-admin/users')->assertForbidden();
        $this->as($this->tech)->putJson("/api/v1/user-admin/users/{$this->tech->id}", ['roles' => [$ownerRole->id]])->assertForbidden();
        $this->as($this->tech)->postJson("/api/v1/user-admin/roles/{$ownerRole->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->as($this->tech)->postJson("/api/v1/user-admin/users/{$this->advisor->id}/reset-password", [
            'password' => 'Hacked123!', 'password_confirmation' => 'Hacked123!',
        ])->assertForbidden();
        $this->as($this->advisor)->postJson('/api/v1/user-admin/branches', ['name' => 'X', 'code' => 'X'])->assertForbidden();
        $this->as($this->advisor)->getJson('/api/v1/user-admin/permissions')->assertForbidden();
        $this->assertFalse($this->tech->fresh()->hasRole(Role::OWNER));

        // The owner manages users, roles and branches as before
        $this->as($this->owner)->getJson('/api/v1/user-admin/users')->assertOk();
        $this->as($this->owner)->getJson('/api/v1/user-admin/roles')->assertOk();
        $this->as($this->owner)->getJson('/api/v1/user-admin/branches')->assertOk();
        $this->as($this->owner)->getJson('/api/v1/user-admin/permissions')->assertOk();
        $this->as($this->owner)->getJson("/api/v1/user-admin/roles/{$ownerRole->id}/permissions")->assertOk();
    }

    public function test_money_paid_beyond_the_bill_is_refunded_or_kept_as_credit_before_the_gate_pass(): void
    {
        $job = $this->readyJob();
        $url = $this->base($job) . '/billing';

        // The customer paid the whole estimate up front, split over cash and UPI
        $summary = $this->as($this->advisor)->postJson("{$url}/payments", ['parts' => [
            ['method' => 'cash', 'amount' => 5000],
            ['method' => 'upi', 'amount' => 6845, 'reference_number' => 'UPI777'],
        ]])->assertCreated()->json('data');
        $this->assertSame('split', $summary['payments'][0]['method']);
        $this->assertCount(2, $summary['payments'][0]['parts']);
        $this->assertEquals(11845, $summary['paid']);

        $bill = $this->as($this->backoffice)->postJson("{$url}/proforma")->assertCreated()->json('data.bill');
        $wheel = $this->line($bill, 'Wheel');
        $this->as($this->backoffice)->deleteJson("{$url}/bills/{$bill['id']}/items/{$wheel['id']}")->assertOk();

        // Approved: the bill is less than what was paid, shown as refund due (not hidden)
        $summary = $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/approve")->assertOk()->json('data');
        $total = $summary['bill_total'];
        $excess = 11845 - $total;
        $this->assertGreaterThan(0, $excess);
        $this->assertEquals(11845, $summary['paid']);
        $this->assertEquals($excess, $summary['refund_due']);
        $proforma = Invoice::where('kind', 'proforma')->sole();
        $this->assertEquals(11845, (float) $proforma->paid_amount);
        $this->assertEquals(-$excess, (float) $proforma->balance_amount);

        // No gate pass while the excess is unsettled, not even by override; no closing on the proforma
        $this->assertNull($summary['gate_pass']);
        $this->as($this->owner)->postJson("{$url}/gate-pass/override", ['reason' => 'Customer in a hurry'])->assertStatus(422);
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/close")->assertStatus(422);

        // Settling needs back office; a refund needs its method; at most the excess
        $this->as($this->advisor)->postJson("{$url}/settle-excess", ['mode' => 'refund', 'method' => 'cash'])->assertForbidden();
        $this->as($this->backoffice)->postJson("{$url}/settle-excess", ['mode' => 'refund'])->assertStatus(422);
        $this->as($this->backoffice)->postJson("{$url}/settle-excess", ['mode' => 'refund', 'method' => 'cash', 'amount' => $excess + 1])->assertStatus(422);

        // Part refunded by UPI, the rest kept as the customer's credit
        $this->as($this->backoffice)->postJson("{$url}/settle-excess", [
            'mode' => 'refund', 'method' => 'upi', 'amount' => 200, 'reference_number' => 'RF1', 'notes' => 'Wheel alignment not done',
        ])->assertOk()->assertJsonPath('data.refund_due', $excess - 200);
        $summary = $this->as($this->backoffice)->postJson("{$url}/settle-excess", ['mode' => 'credit'])->assertOk()->json('data');
        $this->assertEquals(0, $summary['refund_due']);
        $this->assertEquals(0, $summary['balance']);
        $this->assertEquals($total, $summary['paid']);
        $this->assertEquals($excess - 200, $summary['customer_credit']);
        $this->assertSame(['refund', 'credit'], collect($summary['payments'])->slice(1)->pluck('stage')->values()->all());
        $this->assertEquals(-200, $summary['payments'][1]['amount']);

        // Settled: the gate pass is issued by itself (job invoiced, bill exactly paid)
        $this->assertNotNull($summary['gate_pass']);
        $this->assertFalse($summary['gate_pass']['override']);
        $this->assertSame('gate_pass_generated', $job->fresh()->status);
        $this->as($this->backoffice)->postJson("{$url}/settle-excess", ['mode' => 'credit'])->assertStatus(422);

        // Refund voucher and receipts print
        $this->as($this->owner)->get("{$url}/payments/{$summary['payments'][1]['id']}/receipt")->assertOk();
        $this->as($this->owner)->get("{$url}/payments/{$summary['payments'][0]['id']}/receipt")->assertOk();

        // Tax invoice: paid equals its total, no refund due
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/convert")->assertOk();
        $tax = Invoice::where('kind', 'tax')->sole();
        $this->assertEquals((float) $tax->total_amount, (float) $tax->paid_amount);
        $this->assertSame('paid', $tax->status);

        $html = view('exports.bill', [
            'company' => app(CustomerDocumentService::class)->company($job),
            'jobCard' => $job->load(['customer', 'vehicle.make', 'vehicle.model']),
            'bill' => $tax->load(['items', 'customer']),
            'paid' => (float) $tax->paid_amount,
            'words' => '',
            'planName' => null,
        ])->render();
        $this->assertStringNotContainsString('Excess', $html);
        $this->assertStringNotContainsString('Refund due', $html);

        // Collections: cash 5,000 + UPI 6,845 in, UPI 200 refunded; the credit move is not money
        $daily = $this->as($this->owner)->getJson('/api/v1/billing/reports/daily-collection')->assertOk()->json('data');
        $this->assertEquals(11645, $daily['total']);
        $this->assertEquals(5000, $daily['by_method']['cash']);
        $this->assertEquals(6645, $daily['by_method']['upi']);
        $this->assertArrayNotHasKey('customer_credit', $daily['by_method']);
    }

    public function test_customer_credit_pays_a_later_job(): void
    {
        $customer = Customer::findOrFail($this->customerId);
        \App\Modules\Billing\Models\CustomerCredit::create(['customer_id' => $customer->id, 'amount' => 1000, 'note' => 'Earlier overpayment']);

        $job = $this->newJob();
        $url = $this->base($job) . '/billing';
        $this->assertEquals(1000, $this->as($this->advisor)->getJson($url)->assertOk()->json('data.customer_credit'));

        // Not more than the credit available
        $this->as($this->advisor)->postJson("{$url}/payments", ['parts' => [['method' => 'customer_credit', 'amount' => 1500]]])->assertStatus(422);

        $summary = $this->as($this->advisor)->postJson("{$url}/payments", ['parts' => [
            ['method' => 'customer_credit', 'amount' => 600],
            ['method' => 'card', 'amount' => 400, 'reference_number' => 'APPR12'],
        ]])->assertCreated()->json('data');
        $this->assertEquals(1000, $summary['paid']);
        $this->assertEquals(400, $summary['customer_credit']);

        // A cheque part needs its number
        $this->as($this->advisor)->postJson("{$url}/payments", ['parts' => [['method' => 'cheque', 'amount' => 100]]])->assertStatus(422);

        // Today's collection counts the card only: credit is not new money
        $daily = $this->as($this->owner)->getJson('/api/v1/billing/reports/daily-collection')->assertOk()->json('data');
        $this->assertEquals(400, $daily['total']);
    }

    public function test_old_single_method_payments_still_work(): void
    {
        $job = $this->newJob();
        $summary = $this->as($this->advisor)->postJson($this->base($job) . '/billing/payments', ['amount' => 2500, 'method' => 'upi', 'reference_number' => 'U1'])
            ->assertCreated()->json('data');
        $this->assertSame('upi', $summary['payments'][0]['method']);
        $this->assertSame('advance', $summary['payments'][0]['stage']);
        $this->assertCount(1, $summary['payments'][0]['parts']);
    }
    public function test_bill_shows_the_discount_above_the_taxable_value(): void
    {
        $job = $this->readyJob();
        $url = $this->base($job) . '/billing';

        $bill = $this->as($this->backoffice)->postJson("{$url}/proforma")->assertCreated()->json('data.bill');
        $this->assertGreaterThan(0, $bill['discount']);
        $this->assertEqualsWithDelta($bill['taxable_amount'], $bill['value_before_discount'] - $bill['discount'], 0.011);
        // Rs 100 off the extras, before GST (18% inclusive) — the plan's own discount comes on top
        $this->assertGreaterThanOrEqual(round(100 / 1.18, 2) - 0.02, $bill['discount']);

        $proforma = Invoice::where('kind', 'proforma')->sole()->load(['items', 'customer']);
        $html = view('exports.bill', [
            'company' => app(CustomerDocumentService::class)->company($job),
            'jobCard' => $job->load(['customer', 'vehicle.make', 'vehicle.model']),
            'bill' => $proforma,
            'paid' => 0.0,
            'words' => '',
            'planName' => null,
        ])->render();
        $discountAt = strpos($html, 'Less: Discount');
        $this->assertNotFalse($discountAt);
        $this->assertLessThan(strpos($html, 'Taxable value'), $discountAt);
    }

    public function test_activity_can_require_a_photo_and_a_video(): void
    {
        $item = ChecklistItem::whereHas('section')->firstOrFail();
        $section = ChecklistSection::findOrFail($item->section_id);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workshop/job-sheet-masters/sections/{$section->id}/items/{$item->id}", [
            'media_requirement' => 'photo_and_video',
        ])->assertOk();
        $this->assertSame('photo_and_video', $item->fresh()->media_requirement);
        $this->assertTrue((bool) $item->fresh()->requires_photo);

        $rules = app(JobSheetRuleEngine::class);
        $row = new JobCardChecklistItem(['media_requirement' => 'photo_and_video', 'min_media_count' => 1, 'status' => 'done']);
        $media = fn (array $types) => collect($types)->map(fn ($t) => new JobCardMedia(['media_type' => $t]));

        $row->setRelation('media', $media([]));
        $this->assertSame('1 photo and 1 video required', $rules->mediaShortfall($row));
        $row->setRelation('media', $media(['photo']));
        $this->assertSame('1 video required', $rules->mediaShortfall($row));
        $row->setRelation('media', $media(['photo', 'video']));
        $this->assertNull($rules->mediaShortfall($row));
        $this->assertTrue($rules->acceptsMediaType($row, 'video'));
        $this->assertTrue($rules->acceptsMediaType($row, 'photo'));
    }
}
