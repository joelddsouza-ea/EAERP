<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\GoodsReceiptLine;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\PackageOilLitre;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\SparePartQuote;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\StockBalance;
use App\Modules\Workshop\Models\StockItem;
use App\Modules\Workshop\Models\Supplier;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\JobSheetTemplatesSeeder;
use Database\Seeders\OilTypesSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PurchasesPermissionsSeeder;
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
 * Phase 3 purchases module: the switch, GRN with photos, the technician's part check,
 * returns and cancellation, consumables stock and plan kits, supplier dues, purchase
 * register, and the bill waiting for GRNs.
 */
class PurchasesGrnStockTest extends TestCase
{
    use RefreshDatabase;
    use CompletesIntake;

    private User $owner;
    private User $advisor;
    private User $tech;
    private User $tech2;
    private User $coordinator;
    private User $backoffice;
    private int $branchId;
    private Supplier $supplier;

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
            // After the oil grades: their stock items
            PurchasesPermissionsSeeder::class,
        ]);

        Storage::fake('job_media');

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->tech2 = User::where('email', 'tech2@expressautocare.in')->firstOrFail();
        $this->coordinator = User::where('email', 'parts@expressautocare.in')->firstOrFail();
        $this->backoffice = User::where('email', 'backoffice@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;
        $this->supplier = Supplier::create(['name' => 'Brake House', 'gstin' => '27AAAAA0000A1Z5', 'is_active' => true]);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function enable(bool $on = true): void
    {
        WorkshopSettings::set('purchases.enabled', $on);
    }

    private function jobAtWork(string $reg = 'MH05GRN1'): JobCard
    {
        $customer = Customer::create(['name' => 'GRN Test ' . $reg, 'phone' => '90000' . random_int(10000, 99999)]);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id,
            'registration_no' => $reg,
            'category_id' => VehicleCategory::where('slug', 'sedan-compact-suv')->value('id'),
            'fuel_type' => 'petrol',
        ]);
        $id = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'km_reading' => 30000,
            'fuel_level' => 'half',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_type' => 'scheduled',
            'package_id' => ServicePackage::where('slug', 'ea-essentials')->value('id'),
            'oil_type_id' => OilType::where('slug', 'fully-synthetic')->value('id'),
            'technician_id' => $this->tech->id,
            'complaints' => ['Brake noise when stopping'],
        ])->assertCreated()->json('data.id');
        $job = JobCard::withoutGlobalScopes()->findOrFail($id);
        $this->completeIntake($job, $this->advisor->id);

        return $job->fresh();
    }

    private function url(JobCard $job, string $path = ''): string
    {
        return "/api/v1/workshop/job-cards/{$job->id}{$path}";
    }

    /** An approved part on the job's complaint line, priced by Brake House at 1,180 incl. GST */
    private function approvedPart(JobCard $job, string $name = 'Front pad set'): SparePartRequest
    {
        $issue = JobCardIssue::where('job_card_id', $job->id)->firstOrFail();
        $this->as($this->tech)->post($this->url($job, "/issues/{$issue->id}/media"), ['file' => UploadedFile::fake()->image('fault.jpg', 300, 200)])->assertCreated();
        $id = $this->as($this->tech)->postJson($this->url($job, "/issues/{$issue->id}/parts"), ['part_name' => $name, 'part_number' => 'BP-77'])
            ->assertCreated()->json('data.id');
        $request = SparePartRequest::findOrFail($id);
        $quote = SparePartQuote::create([
            'spare_part_request_id' => $id, 'supplier_id' => $this->supplier->id,
            'unit_cost' => 1180, 'gst_mode' => 'incl', 'gst_rate' => 18, 'landed_unit_cost' => 1180,
        ]);
        $request->update(['status' => SparePartRequest::APPROVED, 'selected_quote_id' => $quote->id]);

        return $request->fresh();
    }

    /** POST a GRN; $lines are payload lines, $photos photos per line index */
    private function postGrn(User $user, array $header, array $lines, array $photos = [])
    {
        $files = [];
        foreach ($photos as $i => $count) {
            for ($n = 0; $n < $count; $n++) {
                $files['line_photos'][$i][] = UploadedFile::fake()->image("part{$i}{$n}.jpg", 300, 200);
            }
        }
        $files['bill_photos'] = [UploadedFile::fake()->image('bill.jpg', 600, 800)];

        return $this->as($user)->post('/api/v1/workshop/purchases/grns', [
            'payload' => json_encode([
                'supplier_id' => $this->supplier->id,
                'supplier_bill_no' => 'BH-1001',
                'bill_date' => now()->toDateString(),
                'bill_type' => 'gst_invoice',
                'payment_mode' => 'credit',
                ...$header,
                'lines' => $lines,
            ]),
            ...$files,
        ], ['Accept' => 'application/json']);
    }

    private function issueLine(JobCard $job): array
    {
        return collect($this->as($this->tech)->getJson($this->url($job, '/issues'))->assertOk()->json('data'))->first();
    }

    // ------------------------------------------------------------------ tests

    public function test_module_is_off_by_default_and_the_old_flow_still_works(): void
    {
        $this->as($this->owner)->getJson('/api/v1/workshop/purchases/settings')->assertOk()->assertJsonPath('data.enabled', false);
        $this->as($this->owner)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.features.purchases', false);
        $this->as($this->coordinator)->getJson('/api/v1/workshop/purchases/receivables')->assertNotFound();
        $this->as($this->coordinator)->getJson('/api/v1/workshop/purchases/stock')->assertNotFound();

        $job = $this->jobAtWork();
        $part = $this->approvedPart($job);
        $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$part->id}/received")->assertOk();
        $this->assertSame(SparePartRequest::RECEIVED, $part->fresh()->status);
        // No verification step while the module is off
        $this->assertTrue($this->issueLine($job)['parts_arrived']);

        // Only the owner / manager switch it on
        $this->as($this->coordinator)->putJson('/api/v1/workshop/purchases/settings', ['enabled' => true])->assertForbidden();
        $this->as($this->owner)->putJson('/api/v1/workshop/purchases/settings', ['enabled' => true, 'min_part_photos' => 2])
            ->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.min_part_photos', 2);
        $this->as($this->owner)->getJson('/api/v1/auth/me')->assertJsonPath('data.features.purchases', true);
    }

    public function test_grn_receives_parts_and_the_technician_checks_them(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $part = $this->approvedPart($job);

        // With the module on, parts arrive only on a GRN
        $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$part->id}/received")->assertStatus(422);

        $receivables = $this->as($this->coordinator)->getJson('/api/v1/workshop/purchases/receivables')->assertOk()->json('data');
        $row = collect($receivables['parts'])->firstWhere('id', $part->id);
        $this->assertSame('Brake House', $row['supplier']);
        $this->assertEquals(1180, $row['unit_cost']);
        $this->assertStringContainsString('MH05GRN1', $row['car']);

        $line = ['kind' => 'job_part', 'spare_part_request_id' => $part->id, 'quantity' => 1, 'unit_cost' => 1180, 'gst_mode' => 'incl', 'gst_rate' => 18, 'brand' => 'Bosch'];
        // Advisors can't receive; a part needs its photo
        $this->postGrn($this->advisor, [], [$line], [1])->assertForbidden();
        $this->postGrn($this->coordinator, [], [$line])->assertStatus(422);

        $grn = $this->postGrn($this->coordinator, [], [$line], [1])->assertCreated()->json('data');
        $this->assertStringStartsWith('GRN', $grn['grn_number']);
        $this->assertEquals(1000, $grn['taxable_amount']);
        $this->assertEquals(180, $grn['gst_amount']);
        $this->assertEquals(1180, $grn['total_amount']);
        $this->assertCount(1, $grn['bill_photos']);
        $this->assertCount(1, $grn['lines'][0]['photos']);
        $this->assertSame('pending', $grn['lines'][0]['verification']);
        $this->assertSame(SparePartRequest::RECEIVED, $part->fresh()->status);
        $this->assertContains('parts_received', $this->tech->notifications()->get()->pluck('data.kind')->all());

        // The same part can't be received twice
        $this->postGrn($this->coordinator, [], [$line], [1])->assertStatus(422);

        // Technician: the fitted-part photo and Done wait for the check
        $issue = $this->issueLine($job);
        $this->assertTrue($issue['parts_to_verify']);
        $this->assertFalse($issue['parts_arrived']);
        $this->assertNotNull($issue['part_requests'][0]['grn']);
        // The technician checks the part in person: no GRN photo for them; the advisor still sees it
        $this->assertSame([], $issue['part_requests'][0]['grn']['photos']);
        $advisorView = collect($this->as($this->advisor)->getJson($this->url($job, '/issues'))->assertOk()->json('data'))->first();
        $this->assertCount(1, $advisorView['part_requests'][0]['grn']['photos']);
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$issue['id']}"), ['status' => 'resolved'])
            ->assertStatus(422)->assertJsonPath('message', 'Check the new part that arrived: Right part or Wrong part.');
        $this->as($this->tech)->post($this->url($job, "/issues/{$issue['id']}/media"), [
            'file' => UploadedFile::fake()->image('fit.jpg', 300, 200), 'tag' => 'fitted_part',
        ])->assertStatus(422);

        // Only a technician on the job checks it
        $lineId = $grn['lines'][0]['id'];
        $this->as($this->tech2)->postJson($this->url($job, "/grn-lines/{$lineId}/verify"), ['right' => true])->assertStatus(403);
        $this->as($this->tech)->postJson($this->url($job, "/grn-lines/{$lineId}/verify"), ['right' => true])->assertOk()
            ->assertJsonPath('data.verification', 'verified');
        $this->as($this->tech)->postJson($this->url($job, "/grn-lines/{$lineId}/verify"), ['right' => true])->assertStatus(422);

        $this->assertTrue($this->issueLine($job)['parts_arrived']);
        $this->as($this->tech)->post($this->url($job, "/issues/{$issue['id']}/media"), [
            'file' => UploadedFile::fake()->image('fit.jpg', 300, 200), 'tag' => 'fitted_part',
        ])->assertCreated();
        $this->as($this->tech)->patchJson($this->url($job, "/issues/{$issue['id']}"), ['status' => 'resolved'])->assertOk();

        // An accepted part can't be returned, and its GRN can't be cancelled
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/grn-lines/{$lineId}/return", ['reason' => 'x x x'])->assertStatus(422);
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/grns/{$grn['id']}/cancel", ['reason' => 'Posted by mistake'])->assertStatus(422);
    }

    public function test_wrong_part_goes_back_and_comes_off_the_supplier_due(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $part = $this->approvedPart($job);
        $grn = $this->postGrn($this->coordinator, [], [
            ['kind' => 'job_part', 'spare_part_request_id' => $part->id, 'quantity' => 1, 'unit_cost' => 1000, 'gst_mode' => 'excl', 'gst_rate' => 18],
        ], [1])->assertCreated()->json('data');
        $lineId = $grn['lines'][0]['id'];

        $balance = fn () => collect($this->as($this->backoffice)->getJson('/api/v1/workshop/purchases/suppliers')->assertOk()->json('data.suppliers'))
            ->firstWhere('id', $this->supplier->id);
        $this->assertEquals(1180, $balance()['due']);

        // Wrong part needs a reason; the coordinator and advisor are told; the part is ordered again
        $this->as($this->tech)->postJson($this->url($job, "/grn-lines/{$lineId}/verify"), ['right' => false])->assertStatus(422);
        $this->as($this->tech)->postJson($this->url($job, "/grn-lines/{$lineId}/verify"), ['right' => false, 'note' => 'Rear pads, not front'])->assertOk()
            ->assertJsonPath('data.verification', 'wrong_part');
        $this->assertSame(SparePartRequest::ORDERED, $part->fresh()->status);
        $this->assertContains('wrong_part', $this->coordinator->notifications()->get()->pluck('data.kind')->all());
        $this->assertContains('wrong_part', $this->advisor->notifications()->get()->pluck('data.kind')->all());
        $this->assertFalse($this->issueLine($job)['parts_to_verify']);

        // Returned to the supplier: off the due; the right part comes on a new GRN
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/grn-lines/{$lineId}/return", ['reason' => 'Wrong part'])->assertOk();
        $this->assertEquals(0, $balance()['due']);
        $this->postGrn($this->coordinator, ['supplier_bill_no' => 'BH-1002'], [
            ['kind' => 'job_part', 'spare_part_request_id' => $part->id, 'quantity' => 1, 'unit_cost' => 1000, 'gst_mode' => 'excl', 'gst_rate' => 18],
        ], [1])->assertCreated();
        $this->assertSame(SparePartRequest::RECEIVED, $part->fresh()->status);
        $this->assertTrue($this->issueLine($job)['parts_to_verify']);

        // Supplier payment: back office, not more than owed
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/suppliers/{$this->supplier->id}/payments", ['amount' => 100, 'method' => 'upi'])->assertForbidden();
        $this->as($this->backoffice)->postJson("/api/v1/workshop/purchases/suppliers/{$this->supplier->id}/payments", ['amount' => 5000, 'method' => 'upi'])->assertStatus(422);
        $ledger = $this->as($this->backoffice)->postJson("/api/v1/workshop/purchases/suppliers/{$this->supplier->id}/payments", [
            'amount' => 1000, 'method' => 'bank_transfer', 'reference_number' => 'NEFT1',
        ])->assertOk()->json('data');
        $this->assertEquals(180, $ledger['balance']['due']);
        $this->assertCount(2, $ledger['grns']);
        $this->assertCount(1, $ledger['payments']);
    }

    public function test_cancelling_a_grn_puts_parts_back_to_ordered_and_reverses_stock(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $part = $this->approvedPart($job);
        $filter = StockItem::create(['name' => 'Oil filter (sedan)', 'category' => 'filter', 'unit' => 'nos', 'gst_rate' => 18]);

        $grn = $this->postGrn($this->coordinator, ['bill_type' => 'no_bill', 'supplier_bill_no' => null, 'payment_mode' => 'cash'], [
            ['kind' => 'job_part', 'spare_part_request_id' => $part->id, 'quantity' => 1, 'unit_cost' => 900, 'gst_mode' => 'incl', 'gst_rate' => 18],
            ['kind' => 'stock', 'stock_item_id' => $filter->id, 'quantity' => 10, 'unit_cost' => 150, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ], [1])->assertCreated()->json('data');
        // No bill: no input GST on any line
        $this->assertEquals(0, $grn['gst_amount']);
        $this->assertEquals(2400, $grn['total_amount']);
        $this->assertEquals(10, (float) StockBalance::where('stock_item_id', $filter->id)->value('quantity'));

        // Cash purchases owe nothing
        $this->assertEquals(0, collect($this->as($this->backoffice)->getJson('/api/v1/workshop/purchases/suppliers')->json('data.suppliers'))->firstWhere('id', $this->supplier->id)['due']);

        $this->as($this->advisor)->postJson("/api/v1/workshop/purchases/grns/{$grn['id']}/cancel", ['reason' => 'Posted by mistake'])->assertForbidden();
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/grns/{$grn['id']}/cancel", ['reason' => 'Posted by mistake'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(SparePartRequest::ORDERED, $part->fresh()->status);
        $this->assertEquals(0, (float) StockBalance::where('stock_item_id', $filter->id)->value('quantity'));
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/grns/{$grn['id']}/cancel", ['reason' => 'Again please'])->assertStatus(422);

        // Purchase register leaves cancelled GRNs out of the totals
        $register = $this->as($this->backoffice)->getJson('/api/v1/workshop/purchases/grns')->assertOk()->json('data');
        $this->assertCount(1, $register['grns']);
        $this->assertSame(0, $register['totals']['count']);
    }

    public function test_plan_kit_and_stock_at_weighted_average_cost(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $oil = StockItem::where('oil_type_id', $job->oil_type_id)->firstOrFail();
        $filter = StockItem::create(['name' => 'Oil filter', 'category' => 'filter', 'unit' => 'nos', 'gst_rate' => 18, 'reorder_level' => 5]);
        $categoryId = Vehicle::findOrFail($job->vehicle_id)->category_id;
        $litres = (float) PackageOilLitre::where('package_id', $job->package_id)->where('category_id', $categoryId)->value('litres');
        $this->assertGreaterThan(0, $litres);

        // Plan kit master: the coordinator edits it, advisors can't
        $this->as($this->advisor)->putJson("/api/v1/workshop/purchases/plan-kits/{$job->package_id}", ['lines' => []])->assertForbidden();
        $this->as($this->coordinator)->putJson("/api/v1/workshop/purchases/plan-kits/{$job->package_id}", ['lines' => [
            ['category_id' => null, 'stock_item_id' => $filter->id, 'quantity' => 1],
        ]])->assertOk();

        // Stock in at two prices: 20 L at 500 and 10 L at 650 incl. GST → average 550
        $this->postGrn($this->coordinator, ['payment_mode' => 'cash'], [
            ['kind' => 'stock', 'stock_item_id' => $oil->id, 'quantity' => 20, 'unit_cost' => 500, 'gst_mode' => 'incl', 'gst_rate' => 18],
            ['kind' => 'stock', 'stock_item_id' => $filter->id, 'quantity' => 3, 'unit_cost' => 200, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ])->assertCreated();
        $this->postGrn($this->coordinator, ['payment_mode' => 'cash', 'supplier_bill_no' => 'BH-2'], [
            ['kind' => 'stock', 'stock_item_id' => $oil->id, 'quantity' => 10, 'unit_cost' => 650, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ])->assertCreated();
        $stock = collect($this->as($this->advisor)->getJson('/api/v1/workshop/purchases/stock')->assertOk()->json('data.items'));
        $this->assertEquals(30, $stock->firstWhere('id', $oil->id)['quantity']);
        $this->assertEquals(550, $stock->firstWhere('id', $oil->id)['avg_cost']);
        $this->assertTrue($stock->firstWhere('id', $filter->id)['low']);

        // Suggested kit: oil litres for the plan × class, plus the filter
        $jobStock = $this->as($this->advisor)->getJson($this->url($job, '/stock'))->assertOk()->json('data');
        $kit = collect($jobStock['suggested_kit']);
        $this->assertEquals($litres, $kit->firstWhere('stock_item_id', $oil->id)['quantity']);
        $this->assertEquals(1, $kit->firstWhere('stock_item_id', $filter->id)['quantity']);
        $this->assertNull($jobStock['cost']); // advisors don't see costs

        // More than in stock is refused; the kit is issued in one step
        $this->as($this->advisor)->postJson($this->url($job, '/stock/issue'), ['lines' => [['stock_item_id' => $filter->id, 'quantity' => 9]]])->assertStatus(422);
        $this->as($this->tech)->postJson($this->url($job, '/stock/issue'), ['kit' => true, 'lines' => $kit->map(fn ($k) => ['stock_item_id' => $k['stock_item_id'], 'quantity' => $k['quantity']])->all()])->assertForbidden();
        $issued = $this->as($this->advisor)->postJson($this->url($job, '/stock/issue'), [
            'kit' => true,
            'lines' => $kit->map(fn ($k) => ['stock_item_id' => $k['stock_item_id'], 'quantity' => $k['quantity']])->all(),
        ])->assertOk()->json('data');
        $this->assertNotNull($issued['kit_issued_at']);
        $this->assertEquals(30 - $litres, (float) StockBalance::where('stock_item_id', $oil->id)->value('quantity'));

        // Half a litre back from the job
        $this->as($this->advisor)->postJson($this->url($job, '/stock/return'), ['stock_item_id' => $oil->id, 'quantity' => 0.5])->assertOk();
        $this->as($this->advisor)->postJson($this->url($job, '/stock/return'), ['stock_item_id' => $oil->id, 'quantity' => 99])->assertStatus(422);

        // Owner sees the job's parts cost: oil at 550/L + filter at 200
        $cost = $this->as($this->owner)->getJson($this->url($job, '/stock'))->assertOk()->json('data.cost');
        $this->assertEqualsWithDelta(($litres - 0.5) * 550 + 200, $cost['consumables'], 0.05);

        // Count adjustment: manager only, with a reason
        $this->as($this->coordinator)->postJson("/api/v1/workshop/purchases/stock/{$filter->id}/adjust", ['change' => -1, 'reason' => 'Damaged'])->assertForbidden();
        $this->as($this->owner)->postJson("/api/v1/workshop/purchases/stock/{$filter->id}/adjust", ['change' => -1, 'reason' => 'Damaged'])->assertOk();
        $this->assertEquals(1, (float) StockBalance::where('stock_item_id', $filter->id)->value('quantity'));
        $moves = $this->as($this->owner)->getJson("/api/v1/workshop/purchases/stock/{$filter->id}/movements")->assertOk()->json('data.movements');
        $this->assertSame(['adjust', 'issue', 'in'], array_column($moves, 'type'));
    }

    public function test_plan_kit_is_issued_by_itself_at_final_qc_and_what_is_short_is_kept(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $oil = StockItem::where('oil_type_id', $job->oil_type_id)->firstOrFail();
        $filter = StockItem::create(['name' => 'Oil filter', 'category' => 'filter', 'unit' => 'nos', 'gst_rate' => 18, 'reorder_level' => 5]);
        $litres = (float) PackageOilLitre::where('package_id', $job->package_id)->where('category_id', Vehicle::findOrFail($job->vehicle_id)->category_id)->value('litres');
        $this->as($this->coordinator)->putJson("/api/v1/workshop/purchases/plan-kits/{$job->package_id}", ['lines' => [
            ['category_id' => null, 'stock_item_id' => $filter->id, 'quantity' => 1],
        ]])->assertOk();
        // Only 2 L of the oil in stock
        $this->postGrn($this->coordinator, ['payment_mode' => 'cash'], [
            ['kind' => 'stock', 'stock_item_id' => $oil->id, 'quantity' => 2, 'unit_cost' => 500, 'gst_mode' => 'incl', 'gst_rate' => 18],
            ['kind' => 'stock', 'stock_item_id' => $filter->id, 'quantity' => 3, 'unit_cost' => 200, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ])->assertCreated();

        // The technician's work is done: final QC → the kit goes out of stock as far as it can
        $this->as($this->owner)->patchJson($this->url($job, '/status'), ['status' => 'qc_pending', 'override' => true, 'notes' => 'Test: work done'])->assertOk();
        $job->refresh();
        $this->assertNotNull($job->kit_issued_at);
        $this->assertEquals(2, (float) StockBalance::where('stock_item_id', $filter->id)->value('quantity'));
        $this->assertEquals(0, (float) StockBalance::where('stock_item_id', $oil->id)->value('quantity'));
        $this->assertEqualsWithDelta($litres - 2, $job->kit_shortfall[0]['quantity'], 0.001);
        $this->assertSame(1, $this->coordinator->notifications()->where('data->kind', 'kit_short')->count());
        $this->as($this->advisor)->getJson($this->url($job, '/stock'))->assertOk()->assertJsonPath('data.kit_shortfall.0.stock_item_id', $oil->id);

        // Stock arrives; issuing the rest clears the shortfall
        $this->postGrn($this->coordinator, ['payment_mode' => 'cash', 'supplier_bill_no' => 'BH-9'], [
            ['kind' => 'stock', 'stock_item_id' => $oil->id, 'quantity' => 10, 'unit_cost' => 500, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ])->assertCreated();
        $this->as($this->advisor)->postJson($this->url($job, '/stock/issue'), ['lines' => [['stock_item_id' => $oil->id, 'quantity' => $litres - 2]]])->assertOk();
        $this->assertNull($job->fresh()->kit_shortfall);

        // Module off: nothing is taken
        $this->enable(false);
        $other = $this->jobAtWork('MH05GRN2');
        $this->as($this->owner)->patchJson($this->url($other, '/status'), ['status' => 'qc_pending', 'override' => true, 'notes' => 'Test'])->assertOk();
        $this->assertNull($other->fresh()->kit_issued_at);
    }

    public function test_a_part_from_stock_is_priced_at_average_cost_issued_on_approval_and_billed_without_a_grn(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $wiper = StockItem::create(['name' => 'Wiper blade 22in', 'category' => 'consumable', 'unit' => 'nos', 'gst_rate' => 18, 'reorder_level' => 2]);
        $this->postGrn($this->coordinator, ['payment_mode' => 'cash'], [
            ['kind' => 'stock', 'stock_item_id' => $wiper->id, 'quantity' => 4, 'unit_cost' => 300, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ])->assertCreated();

        $part = $this->approvedPart($job, 'Wiper blade');
        $part->quotes()->delete();
        $part->update(['status' => SparePartRequest::REQUESTED, 'selected_quote_id' => null]);

        // The coordinator prices it from stock: no supplier, average cost
        $this->as($this->tech)->postJson("/api/v1/workshop/parts-desk/requests/{$part->id}/from-stock", ['stock_item_id' => $wiper->id])->assertForbidden();
        $this->as($this->coordinator)->postJson("/api/v1/workshop/parts-desk/requests/{$part->id}/from-stock", ['stock_item_id' => $wiper->id])->assertCreated();
        $part->refresh();
        $this->assertSame(SparePartRequest::QUOTED, $part->status);
        $this->assertNull($part->selectedQuote->supplier_id);
        $this->assertEquals(300, (float) $part->selectedQuote->landed_unit_cost);

        // On the estimate with the usual margin; the customer approves → issued from stock, part arrived
        $estimate = $this->as($this->advisor)->postJson($this->url($job, '/estimates'))->assertOk()->json('data');
        $this->as($this->advisor)->postJson($this->url($job, "/estimates/{$estimate['id']}/parts"), ['spare_part_request_id' => $part->id])->assertCreated();
        $this->as($this->advisor)->postJson($this->url($job, "/estimates/{$estimate['id']}/send"))->assertOk();
        $line = \App\Modules\Workshop\Models\JobCardEstimateLine::where('spare_part_request_id', $part->id)->firstOrFail();
        $this->assertEquals(300, (float) $line->unit_cost);
        $line->update(['approval_status' => 'approved']);
        app(\App\Modules\Workshop\Services\PartsQuoteService::class)->syncFromEstimate($job->fresh(), $line->estimate);

        $this->assertSame(SparePartRequest::RECEIVED, $part->fresh()->status);
        $this->assertEquals(3, (float) StockBalance::where('stock_item_id', $wiper->id)->value('quantity'));
        $this->assertSame(0, $this->coordinator->notifications()->where('data->kind', 'parts_to_procure')->count());

        // The bill doesn't wait for a GRN for it
        $job->update(['status' => 'ready_for_delivery']);
        $summary = $this->as($this->backoffice)->getJson($this->url($job, '/billing'))->assertOk()->json('data');
        $this->assertNull($summary['proforma_from']);
    }

    public function test_bill_waits_for_parts_on_a_grn(): void
    {
        $this->enable();
        $job = $this->jobAtWork();
        $part = $this->approvedPart($job);
        // Priced, put on the estimate, then approved by the customer
        $part->update(['status' => SparePartRequest::QUOTED]);
        $estimate = $this->as($this->advisor)->postJson($this->url($job, '/estimates'))->assertOk()->json('data');
        $this->as($this->advisor)->postJson($this->url($job, "/estimates/{$estimate['id']}/parts"), ['spare_part_request_id' => $part->id])->assertCreated();
        $this->as($this->advisor)->postJson($this->url($job, "/estimates/{$estimate['id']}/send"))->assertOk();
        $line = \App\Modules\Workshop\Models\JobCardEstimateLine::where('spare_part_request_id', $part->id)->firstOrFail();
        $line->update(['approval_status' => 'approved']);
        $part->update(['status' => SparePartRequest::APPROVED, 'estimate_line_key' => $line->line_key]);
        $job->update(['status' => 'ready_for_delivery']);

        $summary = $this->as($this->backoffice)->getJson($this->url($job, '/billing'))->assertOk()->json('data');
        $this->assertStringContainsString('Not received on a GRN yet: Front pad set', $summary['proforma_from']);
        $this->as($this->backoffice)->postJson($this->url($job, '/billing/proforma'))->assertStatus(422);

        $grn = $this->postGrn($this->coordinator, [], [
            ['kind' => 'job_part', 'spare_part_request_id' => $part->id, 'quantity' => 1, 'unit_cost' => 1180, 'gst_mode' => 'incl', 'gst_rate' => 18],
        ], [1])->assertCreated()->json('data');

        $bill = $this->as($this->backoffice)->postJson($this->url($job, '/billing/proforma'))->assertCreated()->json('data.bill');
        $billed = collect($bill['lines'])->first(fn ($l) => str_starts_with($l['description'], 'Front pad set'));
        $this->assertSame($grn['grn_number'], $billed['grn_number']);

        // Switched off, the bill no longer waits
        $this->enable(false);
        $this->as($this->backoffice)->getJson($this->url($job, '/billing'))->assertOk();
    }
}
