<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Modules\Billing\Models\GatePass;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardMedia;
use Illuminate\Http\UploadedFile;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\WorkflowStage;
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
 * Job stages master (automatic moves, customer messages) and billing to delivery:
 * payments, proforma → tax invoice / close, gate pass, vehicle out.
 */
class LifecycleAndBillingTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $backoffice;
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
        WorkflowStage::flushCache();

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->backoffice = User::where('email', 'backoffice@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

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

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function base(JobCard $job): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}";
    }

    private function newJob(): JobCard
    {
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 56518,
            'fuel_level' => 'half',
            'customer_id' => $this->customerId,
            'vehicle_id' => $this->vehicleId,
            'package_id' => ServicePackage::where('slug', 'ea-360')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'service_type' => 'scheduled',
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * Plan Rs 9,575 + two labour jobs (1,770 + 600) with Rs 100 off the extras, all
     * approved by the customer: estimate total Rs 11,845.
     */
    private function jobWithApprovedEstimate(): JobCard
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
        $this->assertSame('awaiting_approval', $job->fresh()->status);
        $this->assertEquals(11845, $sent['estimate']['grand_total']);

        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $this->postJson("/api/v1/public/j/{$token}/decisions", [
            'decisions' => collect($sent['estimate']['additional_lines'])->map(fn ($l) => ['line_key' => $l['line_key'], 'decision' => 'approved'])->all(),
        ])->assertOk();
        $this->assertSame('work_in_progress', $job->fresh()->status);

        return $job->fresh();
    }

    private function billing(JobCard $job): string
    {
        return $this->base($job) . '/billing';
    }

    private function line(array $bill, string $startsWith): array
    {
        // Extra jobs and other items first: plan lines can start with the same words
        return collect($bill['lines'])->sortBy(fn ($l) => $l['section'] === 'plan')->first(fn ($l) => str_starts_with($l['description'], $startsWith));
    }

    // ------------------------------------------------------------------ tests

    public function test_job_stages_master_is_for_owner_and_manager(): void
    {
        $this->as($this->advisor)->getJson('/api/v1/workshop/job-sheet-masters/workflow-stages')->assertForbidden();

        $stages = collect($this->as($this->owner)->getJson('/api/v1/workshop/job-sheet-masters/workflow-stages')->assertOk()->json('data.stages'));
        $this->assertSame(17, $stages->count()); // 12 workshop stages + 3 pickup + 2 home visit
        $this->assertFalse($stages->firstWhere('status', 'intake_pending')['auto_capable']);
        $this->assertTrue($stages->firstWhere('status', 'qc_pending')['auto']);

        $first = WorkflowStage::where('status', 'intake_pending')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$first->id}", ['auto' => true])->assertStatus(422);

        $ready = WorkflowStage::where('status', 'ready_for_delivery')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$ready->id}", ['message' => ''])->assertStatus(422);
        $this->as($this->advisor)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$ready->id}", ['auto' => false])->assertForbidden();
    }

    public function test_a_manual_stage_is_not_entered_by_the_system(): void
    {
        $stage = WorkflowStage::where('status', 'intake_in_progress')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$stage->id}", ['auto' => false])->assertOk();

        $job = $this->newJob();
        $this->as($this->advisor)->putJson($this->base($job) . '/intake/trial', ['status' => 'done'])->assertOk();
        $this->assertSame('intake_pending', $job->fresh()->status);

        // A manual stage is moved by the advisor as before, no reason needed
        $this->as($this->advisor)->patchJson($this->base($job) . '/status', ['status' => 'intake_in_progress'])->assertOk();
        $this->assertSame('intake_in_progress', $job->fresh()->status);
    }

    public function test_stage_messages_come_from_the_master(): void
    {
        $stage = WorkflowStage::where('status', 'intake_in_progress')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$stage->id}", [
            'message' => 'Namaste :name! :car is with us (Job :job).',
        ])->assertOk();

        $job = $this->newJob();
        $this->as($this->advisor)->putJson($this->base($job) . '/intake/trial', ['status' => 'done'])->assertOk();

        $message = CustomerMessage::where('job_card_id', $job->id)->where('template', 'stage:intake_in_progress')->sole();
        $this->assertSame("Namaste Pranav Shroff! MH05DS4417 is with us (Job {$job->job_number}).", $message->body);

        // Messages switched off: nothing is prepared
        $work = WorkflowStage::where('status', 'work_in_progress')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$work->id}", ['notify_customer' => 'off'])->assertOk();
        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $this->assertSame('work_in_progress', $job->fresh()->status);
        $this->assertFalse(CustomerMessage::where('job_card_id', $job->id)->where('template', 'stage:work_in_progress')->exists());
    }

    public function test_payments_proforma_tax_invoice_gate_pass_and_delivery(): void
    {
        $job = $this->jobWithApprovedEstimate();
        $url = $this->billing($job);

        // Advance while work is going on
        $this->as($this->tech)->postJson("{$url}/payments", ['amount' => 5000, 'method' => 'cash'])->assertForbidden();
        $summary = $this->as($this->advisor)->postJson("{$url}/payments", ['amount' => 5000, 'method' => 'upi', 'reference_number' => 'UPI123'])
            ->assertCreated()->json('data');
        $this->assertSame('advance', $summary['payments'][0]['stage']);
        $this->assertEquals(5000, $summary['paid']);
        $this->assertSame('payment_received', CustomerMessage::where('job_card_id', $job->id)->latest('id')->first()->template);

        // The final bill comes after the final QC
        $this->as($this->backoffice)->postJson("{$url}/proforma")->assertStatus(422);
        $job->update(['status' => 'ready_for_delivery']);

        // Proforma from the estimate: every plan line with HSN/SAC, GST-inclusive with 18% extracted,
        // extras discount spread over the lines
        $this->as($this->tech)->postJson("{$url}/proforma")->assertForbidden();
        $bill = $this->as($this->backoffice)->postJson("{$url}/proforma")->assertCreated()->json('data.bill');
        $this->assertSame('proforma', $bill['kind']);
        $this->assertStringStartsWith('PRF', $bill['number']);
        $this->assertEquals(11845, $bill['total']);
        $plan = collect($bill['lines'])->where('section', 'plan');
        $this->assertGreaterThan(3, $plan->count());
        $this->assertEqualsWithDelta(9575, $plan->sum('total'), 0.001);
        $this->assertSame('27101980', $plan->firstWhere(fn ($l) => str_contains($l['description'], 'Engine Oil'))['hsn_code']);
        $this->assertSame('84212300', $this->line($bill, 'Oil Filter')['hsn_code']);
        $this->assertSame('998714', $plan->firstWhere(fn ($l) => str_contains($l['description'], '(Labour)'))['hsn_code']);
        $this->assertTrue($plan->every(fn ($l) => $l['tax_rate'] == 18 && $l['gst_inclusive']));
        $this->assertEquals(74.68, $this->line($bill, 'Throttle')['discount']);
        $this->assertEquals(25.32, $this->line($bill, 'Wheel')['discount']);

        // Back office trims the final bill and adds other items (GST yes/no, rate on top)
        $this->as($this->backoffice)->deleteJson("{$url}/bills/{$bill['id']}/items/" . $this->line($bill, 'Wheel')['id'])->assertOk();
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/items", [
            'description' => 'Car perfume', 'quantity' => 1, 'unit_price' => 200, 'gst_applicable' => false,
        ])->assertCreated();
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/items", [
            'description' => 'Tyre puncture', 'quantity' => 1, 'unit_price' => 100, 'gst_applicable' => true, 'tax_rate' => 7,
        ])->assertStatus(422);
        $bill = $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/items", [
            'description' => 'Tyre puncture', 'quantity' => 1, 'unit_price' => 100, 'gst_applicable' => true, 'tax_rate' => 5,
        ])->assertCreated()->json('data.bill');
        $this->assertEquals(0, $this->line($bill, 'Car perfume')['tax']);
        $this->assertEquals(105, $this->line($bill, 'Tyre puncture')['total']);
        // 9575 + 1695.32 + 200 + 105, rounded to the rupee
        $this->assertEquals(11575, $bill['total']);

        // Back office can correct a line's HSN/SAC
        $kit = $this->line($bill, 'Car perfume');
        $this->as($this->backoffice)->patchJson("{$url}/bills/{$bill['id']}/items/{$kit['id']}", ['hsn_code' => 'abc'])->assertStatus(422);
        $this->as($this->backoffice)->patchJson("{$url}/bills/{$bill['id']}/items/{$kit['id']}", ['hsn_code' => '33074900'])->assertOk();

        // Approved → Invoiced by itself; the advance counts; the customer gets the bill message
        $this->as($this->tech)->postJson("{$url}/bills/{$bill['id']}/approve")->assertForbidden();
        $summary = $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/approve")->assertOk()->json('data');
        $this->assertEquals(6575, $summary['balance']);
        $this->assertSame('invoiced', $job->fresh()->status);
        $this->assertSame(Invoice::where('kind', 'proforma')->value('id'), Payment::where('job_card_id', $job->id)->value('invoice_id'));

        $this->as($this->advisor)->postJson("{$url}/payments", ['amount' => 3000, 'method' => 'card'])->assertCreated()
            ->assertJsonPath('data.payments.1.stage', 'part')
            ->assertJsonPath('data.gate_pass', null);
        $this->assertSame('invoiced', $job->fresh()->status);
        $invoiced = CustomerMessage::where('job_card_id', $job->id)->where('template', 'stage:invoiced')->sole();
        $this->assertStringContainsString('Rs 11,575', $invoiced->body);

        // The customer's page shows the bill and its PDF
        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $this->getJson("/api/v1/public/j/{$token}")->assertOk()
            ->assertJsonPath('data.bill.total', 11575)
            ->assertJsonPath('data.bill.balance', 3575);
        $this->get("/api/v1/public/j/{$token}/bill.pdf")->assertOk();

        $this->as($this->advisor)->postJson("{$url}/payments", ['amount' => 4000, 'method' => 'cash'])->assertStatus(422);
        $this->as($this->advisor)->postJson("{$url}/gate-pass/vehicle-out")->assertStatus(422);

        // GST tax invoice from the proforma: the deleted line stays out, payments follow.
        // Only back office, managers and the owner finish billing (not advisors).
        $this->as($this->advisor)->postJson("{$url}/bills/{$bill['id']}/convert", [])->assertForbidden();
        $this->as($this->advisor)->postJson("{$url}/bills/{$bill['id']}/close", [])->assertForbidden();
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/convert", ['customer_gstin' => 'bad'])->assertStatus(422);
        // Lines can also be left off in the convert step itself
        $summary = $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/items", [
            'description' => 'Wash only', 'quantity' => 1, 'unit_price' => 50, 'gst_applicable' => false,
        ])->assertCreated()->json('data');
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/convert", [
            'exclude_item_ids' => collect($summary['bill']['lines'])->pluck('id')->all(),
        ])->assertStatus(422);
        $summary = $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/convert", [
            'customer_gstin' => '27ABCDE1234F1Z5',
            'exclude_item_ids' => [$this->line($summary['bill'], 'Wash only')['id']],
        ])->assertOk()->json('data');
        $tax = Invoice::where('kind', 'tax')->sole();
        $this->assertStringStartsWith('INV', $tax->invoice_number);
        $this->assertSame('partial', $tax->status);
        $this->assertSame('converted', Invoice::where('kind', 'proforma')->value('status'));
        // Same lines as the trimmed proforma: every plan line, the throttle job and the two other items
        $this->assertSame(Invoice::where('kind', 'proforma')->sole()->items()->count(), $tax->items()->count());
        $this->assertSame(['additional', 'other', 'plan'], $tax->items()->pluck('section')->unique()->sort()->values()->all());
        $this->assertFalse($tax->items()->where('description', 'Wheel Alignment')->exists());
        $this->assertEquals(11575, $tax->total_amount);
        $this->assertEquals(8000, $tax->paid_amount);
        $this->assertSame($tax->id, $summary['bill']['id']);
        $this->assertEquals((float) $tax->cgst, (float) $tax->sgst);

        // Final payment → gate pass by itself → vehicle out → delivered
        $summary = $this->as($this->advisor)->postJson("{$url}/payments", ['amount' => 3575, 'method' => 'cash'])->assertCreated()->json('data');
        $this->assertSame('final', $summary['payments'][2]['stage']);
        $this->assertFalse($summary['gate_pass']['override']);
        $this->assertSame('paid', $tax->fresh()->status);
        $this->assertSame('gate_pass_generated', $job->fresh()->status);

        $this->as($this->owner)->get("{$url}/bills/{$tax->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->as($this->owner)->get("{$url}/payments/" . Payment::first()->id . '/receipt')->assertOk();
        $this->as($this->owner)->get("{$url}/gate-pass/pdf")->assertOk();

        $this->as($this->advisor)->postJson("{$url}/gate-pass/vehicle-out")->assertOk();
        $this->assertSame('delivered', $job->fresh()->status);
        $this->assertNotNull(GatePass::sole()->vehicle_out_at);
    }

    public function test_advisor_sends_for_billing_and_bill_lines_are_rechecked(): void
    {
        $job = $this->jobWithApprovedEstimate();
        $url = $this->billing($job);

        // Only after the final QC
        $this->as($this->advisor)->postJson("{$url}/request")->assertStatus(422);
        $job->update(['status' => 'ready_for_delivery']);
        $this->as($this->tech)->postJson("{$url}/request")->assertForbidden();
        $summary = $this->as($this->advisor)->postJson("{$url}/request")->assertOk()->json('data');
        $this->assertNotNull($summary['billing_requested']);
        $this->assertNotNull($job->fresh()->billing_requested_at);
        $this->assertSame(1, $this->backoffice->notifications()->where('data->kind', 'bill_requested')->count());
        $this->assertSame(1, collect(app(\App\Core\Services\WorkspaceService::class)->myDay($this->backoffice)['cards'])->firstWhere('key', 'bills')['count']);

        // The advisor may make and approve the bill too; lines keep their cost and can be rechecked
        $bill = $this->as($this->advisor)->postJson("{$url}/proforma")->assertCreated()->json('data.bill');
        $this->as($this->advisor)->postJson("{$url}/request")->assertStatus(422);
        $line = $this->line($bill, 'Throttle');
        $this->as($this->advisor)->patchJson("{$url}/bills/{$bill['id']}/items/{$line['id']}", ['quantity' => 0])->assertStatus(422);
        $bill = $this->as($this->backoffice)->patchJson("{$url}/bills/{$bill['id']}/items/{$line['id']}", [
            'quantity' => 2, 'unit_price' => 1500, 'unit_cost' => 900,
        ])->assertOk()->json('data.bill');
        $edited = $this->line($bill, 'Throttle');
        $this->assertEquals(2, $edited['quantity']);
        $this->assertEquals(1500, $edited['unit_price']);
        $this->assertEquals(900, $edited['unit_cost']);
        // 2 x 1500 less its share of the discount, GST inclusive
        $this->assertEqualsWithDelta(3000 - $edited['discount'], $edited['total'], 0.01);
        $this->assertEqualsWithDelta(collect($bill['lines'])->sum('total'), $bill['total'], 1);

        $this->as($this->advisor)->postJson("{$url}/bills/{$bill['id']}/approve")->assertOk();
        $this->assertSame('invoiced', $job->fresh()->status);
        $this->as($this->advisor)->postJson("{$url}/bills/{$bill['id']}/close", [])->assertForbidden();
        $this->as($this->advisor)->patchJson("{$url}/bills/{$bill['id']}/items/{$line['id']}", ['quantity' => 1])->assertOk();
    }

    public function test_gate_pass_before_full_payment_needs_an_owner_or_manager_and_a_reason(): void
    {
        $job = $this->jobWithApprovedEstimate();
        $url = $this->billing($job);

        // Not before the car is ready and billed
        $this->as($this->owner)->postJson("{$url}/gate-pass/override", ['reason' => 'Fleet customer, monthly billing'])->assertStatus(422);
        $job->update(['status' => 'ready_for_delivery']);
        $bill = $this->as($this->backoffice)->postJson("{$url}/proforma")->assertCreated()->json('data.bill');
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/approve")->assertOk();
        $this->as($this->advisor)->postJson("{$url}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->assertSame('invoiced', $job->fresh()->status);

        $this->as($this->advisor)->postJson("{$url}/gate-pass/override", ['reason' => 'Fleet customer, monthly billing'])->assertForbidden();
        $this->as($this->owner)->postJson("{$url}/gate-pass/override", [])->assertStatus(422);
        $pass = $this->as($this->owner)->postJson("{$url}/gate-pass/override", ['reason' => 'Fleet customer, monthly billing'])
            ->assertOk()->json('data.gate_pass');

        $this->assertTrue($pass['override']);
        $this->assertEquals(10845, $pass['amount_due']);
        $this->assertSame('gate_pass_generated', $job->fresh()->status);

        // Closing on the proforma needs full payment
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/close")->assertStatus(422);
        $this->as($this->advisor)->postJson("{$url}/payments", ['amount' => 10845, 'method' => 'bank_transfer'])->assertCreated();
        $this->as($this->backoffice)->postJson("{$url}/bills/{$bill['id']}/close", ['note' => 'No GST invoice needed'])->assertOk()
            ->assertJsonPath('data.bill.status', 'closed')
            ->assertJsonPath('data.balance', 0);
    }

    public function test_customer_gallery_is_section_wise_refreshes_and_is_announced_at_chosen_stages(): void
    {
        $job = $this->newJob();
        $this->as($this->advisor)->putJson($this->base($job) . '/intake/trial', ['status' => 'done'])->assertOk();
        $token = CustomerLink::where('job_card_id', $job->id)->whereNull('revoked_at')->latest('id')->firstOrFail()->token;
        $version = fn () => $this->getJson("/api/v1/public/j/{$token}/version")->assertOk()->json('data.version');

        // A new intake photo shows on the customer's page by itself; the page sees the change
        $before = $version();
        $this->as($this->advisor)->post($this->base($job) . '/intake/media', ['file' => UploadedFile::fake()->image('front.jpg', 400, 300)])->assertCreated();
        $this->assertNotSame($before, $version());
        $gallery = $this->getJson("/api/v1/public/j/{$token}")->json('data.gallery');
        $this->assertSame(['intake'], array_column($gallery, 'key'));
        $this->assertSame('Your car at arrival', $gallery[0]['title']);

        // Work starts: the "work started" message also tells about the new photos (one message, not two)
        $job->update(['technician_id' => $this->tech->id]);
        $this->completeIntake($job, $this->advisor->id);
        $this->assertSame('work_in_progress', $job->fresh()->status);
        $message = CustomerMessage::where('job_card_id', $job->id)->where('template', 'stage:work_in_progress')->sole();
        $this->assertStringContainsString('Your car at arrival', $message->body);
        $this->assertFalse(CustomerMessage::where('job_card_id', $job->id)->where('template', 'gallery_update')->exists());
        $this->assertNotNull($job->fresh()->gallery_notified_media_id);

        // Job-sheet photos are grouped by the sheet's sections and labelled with the activity
        $sheet = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'service_activities')->firstOrFail();
        $item = $sheet->items()->where('media_requirement', '!=', 'none')->firstOrFail();
        $this->as($this->tech)->post($this->base($job) . "/job-sheets/{$sheet->id}/items/{$item->id}/media", [
            'file' => UploadedFile::fake()->image('oil.jpg', 400, 300),
        ])->assertCreated();

        // Final-QC evidence never reaches the customer
        $pre = JobCardChecklist::where('job_card_id', $job->id)->where('sheet_kind', 'pre_delivery')->firstOrFail();
        JobCardMedia::create([
            'job_card_id' => $job->id, 'attachable_type' => 'sheet_item', 'attachable_id' => $pre->items()->value('id'),
            'media_type' => 'photo', 'disk' => 'job_media', 'file_path' => 'qc/x.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 10,
            'uploaded_by' => $this->advisor->id, 'customer_visible' => true,
        ]);

        $gallery = collect($this->getJson("/api/v1/public/j/{$token}")->json('data.gallery'));
        $work = $gallery->first(fn ($a) => str_starts_with($a['key'], 'sheet-'));
        $this->assertSame(2, $gallery->count());
        $this->assertSame($item->section_name, $work['groups'][0]['title']);
        $this->assertSame($item->item_name, $work['groups'][0]['items'][0]['caption']);

        // A stage with no message of its own sends the photo update alone; only what is new since last time
        $job->update(['status' => 'work_in_progress']);
        app(\App\Modules\Workshop\Services\JobCardStatusService::class)->changeBySystem($job->fresh(), 'awaiting_parts', 'test');
        $this->assertFalse(CustomerMessage::where('job_card_id', $job->id)->where('template', 'gallery_update')->exists());
        $stage = WorkflowStage::where('status', 'awaiting_parts')->firstOrFail();
        $this->as($this->owner)->putJson("/api/v1/workshop/job-sheet-masters/workflow-stages/{$stage->id}", ['gallery_update' => true])->assertOk();
        app(\App\Modules\Workshop\Services\JobCardStatusService::class)->changeBySystem($job->fresh(), 'work_in_progress', 'test');
        app(\App\Modules\Workshop\Services\JobCardStatusService::class)->changeBySystem($job->fresh(), 'awaiting_parts', 'test');
        $update = CustomerMessage::where('job_card_id', $job->id)->where('template', 'gallery_update')->sole();
        $this->assertStringContainsString(\App\Modules\Workshop\Services\CustomerGalleryService::sheetTitle($sheet->name) . ': 1', $update->body);
        $this->assertStringNotContainsString('Your car at arrival', $update->body);
    }

    public function test_bill_needs_a_fully_answered_estimate(): void
    {
        $job = $this->newJob();
        $url = $this->billing($job);

        $this->as($this->backoffice)->getJson($url)->assertOk()
            ->assertJsonPath('data.proforma_from', 'Send the estimate to the customer first: the bill is made from it.');
        $this->as($this->backoffice)->postJson("{$url}/proforma")->assertStatus(422);
    }
}
