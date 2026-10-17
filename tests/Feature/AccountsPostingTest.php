<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AccountingPeriod;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Accounts\Models\JournalLine;
use App\Modules\Accounts\Services\AccountsReportService;
use App\Modules\Accounts\Services\LedgerService;
use App\Modules\Accounts\Support\ChartOfAccounts as Coa;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Hr\Services\StaffMoneyService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\Supplier;
use App\Modules\Workshop\Models\Vehicle;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6: bills, receipts, purchases, expenses and payroll post balanced entries by themselves.
 */
class AccountsPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $backoffice;
    private User $tech;
    private User $advisor;
    private Branch $branch;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->backoffice = User::where('email', 'backoffice@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->branch = $this->tech->branches()->firstOrFail();
        $this->customer = Customer::create(['name' => 'Kavita', 'phone' => '9000000003']);
        $this->travelTo('2026-10-15 11:00');
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branch->id);
    }

    private function balance(string $subType, ?array $party = null): float
    {
        return app(LedgerService::class)->balance(Coa::id($subType), null, null, null, $party);
    }

    private function assertBooksBalance(): void
    {
        $tb = app(AccountsReportService::class)->trialBalance('2030-12-31');
        $this->assertEqualsWithDelta($tb['total_debit'], $tb['total_credit'], 0.001, 'Trial balance does not tally');
        foreach (JournalEntry::with('lines')->get() as $e) {
            $this->assertEqualsWithDelta($e->lines->sum('debit'), $e->lines->sum('credit'), 0.001, "{$e->number} does not balance");
        }
    }

    private function bill(): Invoice
    {
        $vehicle = Vehicle::create(['customer_id' => $this->customer->id, 'registration_no' => 'MH04CC3333']);
        $jobId = DB::table('job_cards')->insertGetId([
            'branch_id' => $this->branch->id, 'job_number' => 'JC-A-1', 'customer_id' => $this->customer->id, 'vehicle_id' => $vehicle->id,
            'status' => 'invoiced', 'service_type' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bill = Invoice::create([
            'branch_id' => $this->branch->id, 'job_card_id' => $jobId, 'customer_id' => $this->customer->id, 'kind' => 'tax',
            'invoice_number' => 'INV-ACC-1', 'invoice_date' => today()->toDateString(), 'status' => 'issued',
        ]);
        $bill->items()->create(['type' => 'labor', 'description' => 'Labour', 'quantity' => 1, 'unit_price' => 2360, 'tax_rate' => 18, 'gst_inclusive' => true]);
        $bill->items()->create(['type' => 'part', 'description' => 'Oil filter', 'quantity' => 1, 'unit_price' => 590.40, 'tax_rate' => 18, 'gst_inclusive' => true]);

        return $bill->fresh();
    }

    public function test_tax_invoice_and_receipts_post_to_sales_gst_debtors_cash_bank_and_staff(): void
    {
        $bill = $this->bill();
        $customer = [JournalLine::PARTY_CUSTOMER, $this->customer->id];

        // Sales ex-GST by kind, output GST, rounded total owed by the customer
        $this->assertEquals((float) $bill->total_amount, $this->balance(Coa::DEBTORS, $customer));
        $this->assertEquals(-2000.0, $this->balance(Coa::SALES_SERVICES));
        $this->assertEquals(-500.34, $this->balance(Coa::SALES_PARTS));
        $this->assertEqualsWithDelta(-(float) $bill->cgst, $this->balance(Coa::OUTPUT_CGST), 0.001);
        $this->assertBooksBalance();

        // Split receipt: cash + UPI + money collected by the technician on their own UPI
        $res = $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$bill->job_card_id}/billing/payments", ['parts' => [
            ['method' => 'cash', 'amount' => 1000],
            ['method' => 'upi', 'amount' => 900, 'reference_number' => 'UPI42'],
            ['method' => 'staff_collected', 'amount' => 1050],
        ]]);
        $res->assertStatus(422)->assertJsonFragment(['parts.2.collected_by' => ['Who collected it?']]);

        $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$bill->job_card_id}/billing/payments", ['parts' => [
            ['method' => 'cash', 'amount' => 1000],
            ['method' => 'upi', 'amount' => 900, 'reference_number' => 'UPI42'],
            ['method' => 'staff_collected', 'amount' => 1050, 'collected_by' => $this->tech->id],
        ]])->assertCreated();

        $this->assertEquals(1000.0, $this->balance(Coa::CASH));
        $this->assertEquals(900.0, $this->balance(Coa::BANK));
        $this->assertEquals(1050.0, $this->balance(Coa::STAFF_CUSTODY, [JournalLine::PARTY_USER, $this->tech->id]));
        $this->assertEquals(round((float) $bill->total_amount - 2950, 2), $this->balance(Coa::DEBTORS, $customer));
        $this->assertEquals(1050, StaffCustodyEntry::balanceFor($this->tech->id));

        // The technician hands 1,000 over to the cash box
        $this->as($this->backoffice)->postJson('/api/v1/hr/custody/hand-over', ['user_id' => $this->tech->id, 'amount' => 2000, 'method' => 'cash'])->assertStatus(422);
        $this->as($this->backoffice)->postJson('/api/v1/hr/custody/hand-over', ['user_id' => $this->tech->id, 'amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->assertEquals(2000.0, $this->balance(Coa::CASH));
        $this->assertEquals(50.0, $this->balance(Coa::STAFF_CUSTODY, [JournalLine::PARTY_USER, $this->tech->id]));

        // Cancelling the bill in the same month takes it off the books
        $bill->update(['status' => 'cancelled']);
        $this->assertEquals(0.0, $this->balance(Coa::SALES_SERVICES));
        $this->assertBooksBalance();
    }

    public function test_grn_supplier_payment_and_cancel_in_a_locked_month_reverses(): void
    {
        $supplier = Supplier::create(['name' => 'Mohit Car Parts']);
        $grn = GoodsReceipt::create([
            'branch_id' => $this->branch->id, 'grn_number' => 'GRN-T-1', 'supplier_id' => $supplier->id, 'bill_date' => '2026-09-28',
            'bill_type' => 'gst_invoice', 'payment_mode' => 'credit', 'status' => 'posted',
        ]);
        $grn->lines()->create([
            'kind' => 'third_party', 'description' => 'Disc skimming', 'quantity' => 1, 'unit_cost' => 1180, 'gst_mode' => 'incl',
            'gst_rate' => 18, 'taxable_amount' => 1000, 'gst_amount' => 180, 'total' => 1180,
        ]);

        $owed = [JournalLine::PARTY_SUPPLIER, $supplier->id];
        $this->assertEquals(-1180.0, $this->balance(Coa::CREDITORS, $owed));
        $this->assertEquals(1000.0, $this->balance(Coa::THIRD_PARTY));
        $this->assertEquals(90.0, $this->balance(Coa::INPUT_CGST));

        // Paid from the bank
        \App\Modules\Workshop\Models\SupplierPayment::create(['branch_id' => $this->branch->id, 'supplier_id' => $supplier->id, 'amount' => 1180, 'method' => 'bank_transfer', 'paid_on' => today()->toDateString()]);
        $this->assertEquals(0.0, $this->balance(Coa::CREDITORS, $owed));
        $this->assertEquals(-1180.0, $this->balance(Coa::BANK));

        // September is locked: cancelling the GRN now reverses it today, September stays as it was
        AccountingPeriod::create(['period' => '2026-09', 'locked_by' => $this->owner->id, 'locked_at' => now()]);
        $grn->update(['status' => 'cancelled']);

        $original = JournalEntry::where('source_type', 'grn')->whereNull('reversal_of')->firstOrFail();
        $this->assertSame('reversed', $original->status);
        $reversal = JournalEntry::where('reversal_of', $original->id)->firstOrFail();
        $this->assertSame('2026-10-15', $reversal->entry_date->toDateString());
        $this->assertEquals(0.0, $this->balance(Coa::THIRD_PARTY));
        $this->assertEquals(1000.0, app(LedgerService::class)->balance(Coa::id(Coa::THIRD_PARTY), '2026-09-30'));

        // Hand-made vouchers can't go into a locked month
        $this->as($this->owner)->postJson('/api/v1/accounts/entries', [
            'voucher_type' => 'journal', 'entry_date' => '2026-09-30', 'narration' => 'Late entry',
            'lines' => [['account_id' => Coa::id(Coa::CASH), 'debit' => 10], ['account_id' => Coa::id(Coa::CAPITAL), 'credit' => 10]],
        ])->assertStatus(422);
        $this->assertBooksBalance();
    }

    public function test_expenses_from_cash_bank_and_staff_held_money(): void
    {
        $head = Account::where('code', '6220')->firstOrFail(); // Fuel
        $this->as($this->tech)->postJson('/api/v1/accounts/expenses', [])->assertForbidden();

        // GST bill paid in cash: input GST claimed
        $this->as($this->advisor)->postJson('/api/v1/accounts/expenses', [
            'expense_date' => today()->toDateString(), 'account_id' => $head->id, 'paid_to' => 'HP petrol pump',
            'amount' => 1180, 'gst_bill' => true, 'gst_rate' => 18, 'paid_via' => 'cash',
        ])->assertCreated();
        $this->assertEquals(1000.0, app(LedgerService::class)->balance($head->id));
        $this->assertEquals(-1180.0, $this->balance(Coa::CASH));
        $this->assertEquals(90.0, $this->balance(Coa::INPUT_SGST));

        // Paid from customer money the technician holds: only what they hold
        StaffCustodyEntry::create(['user_id' => $this->tech->id, 'branch_id' => $this->branch->id, 'entry_date' => today()->toDateString(), 'amount' => 500, 'kind' => 'collected']);
        $payload = ['expense_date' => today()->toDateString(), 'account_id' => $head->id, 'paid_to' => 'Porter', 'paid_via' => 'staff_custody', 'paid_by_user_id' => $this->tech->id];
        $this->as($this->advisor)->postJson('/api/v1/accounts/expenses', [...$payload, 'amount' => 800])->assertStatus(422);
        $id = $this->as($this->advisor)->postJson('/api/v1/accounts/expenses', [...$payload, 'amount' => 300])->assertCreated()->json('data.id');
        $this->assertEquals(200, StaffCustodyEntry::balanceFor($this->tech->id));

        // Cancelled: the money is back with the technician and off the books
        $this->as($this->backoffice)->postJson("/api/v1/accounts/expenses/{$id}/cancel", ['reason' => 'Duplicate'])->assertOk();
        $this->assertEquals(500, StaffCustodyEntry::balanceFor($this->tech->id));
        $this->assertBooksBalance();

        // P&L and balance sheet agree
        $reports = app(AccountsReportService::class);
        $pl = $reports->profitAndLoss('2026-10-01', '2026-10-31');
        $this->assertEquals(-1000.0, $pl['net_profit']);
        $bs = $reports->balanceSheet('2026-10-31');
        $this->assertEqualsWithDelta($bs['total_assets'], $bs['total_liabilities_equity'], 0.001);
    }

    public function test_manual_journal_must_balance_and_reports_need_permission(): void
    {
        $this->as($this->owner)->postJson('/api/v1/accounts/entries', [
            'voucher_type' => 'opening', 'entry_date' => today()->toDateString(), 'narration' => 'Opening cash',
            'lines' => [['account_id' => Coa::id(Coa::CASH), 'debit' => 5000], ['account_id' => Coa::id(Coa::OPENING), 'credit' => 4000]],
        ])->assertStatus(422);

        $id = $this->as($this->owner)->postJson('/api/v1/accounts/entries', [
            'voucher_type' => 'opening', 'entry_date' => today()->toDateString(), 'narration' => 'Opening cash',
            'lines' => [['account_id' => Coa::id(Coa::CASH), 'debit' => 5000], ['account_id' => Coa::id(Coa::OPENING), 'credit' => 5000]],
        ])->assertCreated()->json('data.id');
        $this->assertEquals(5000.0, $this->balance(Coa::CASH));

        $this->as($this->owner)->postJson("/api/v1/accounts/entries/{$id}/reverse", ['reason' => 'Wrong amount'])->assertOk();
        $this->assertEquals(0.0, $this->balance(Coa::CASH));

        $this->as($this->tech)->getJson('/api/v1/accounts/reports/profit-loss')->assertForbidden();
        $this->as($this->backoffice)->getJson('/api/v1/accounts/reports/profit-loss')->assertOk();
        $this->as($this->backoffice)->getJson('/api/v1/accounts/reports/gst')->assertOk()->assertJsonStructure(['data' => ['output', 'input', 'net_payable']]);
        $this->as($this->owner)->get('/api/v1/accounts/reports/vouchers.csv')->assertOk();
    }

    public function test_resync_posts_history_once(): void
    {
        $this->bill();
        $count = JournalEntry::count();

        $this->artisan('accounts:resync')->assertSuccessful();
        $this->artisan('accounts:resync')->assertSuccessful();
        $this->assertSame($count, JournalEntry::count());
    }
}
