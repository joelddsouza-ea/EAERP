<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\Vehicle;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Settings › Modules: Accounts, CRM and Attendance & payroll can be switched off like
 * Purchases & stock; their screens / API and background work stop, nothing else breaks.
 */
class ModuleSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $advisor;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->branch = $this->advisor->branches()->firstOrFail();
        $this->travelTo('2026-11-02 11:00');
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branch->id);
    }

    private function job(): array
    {
        $customer = Customer::create(['name' => 'Mod Test', 'phone' => '9820700000']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH01MO0001']);
        $jobId = DB::table('job_cards')->insertGetId([
            'branch_id' => $this->branch->id, 'job_number' => 'JC-M-1', 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'status' => 'invoiced', 'service_type' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer, $vehicle, $jobId];
    }

    public function test_all_on_by_default_and_only_the_owner_switches(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'advisor@expressautocare.in', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.features.accounts', true)
            ->assertJsonPath('data.user.features.crm', true)
            ->assertJsonPath('data.user.features.hr', true);

        $this->as($this->manager)->putJson('/api/v1/settings/modules', ['crm' => false])->assertForbidden();
        $this->as($this->owner)->putJson('/api/v1/settings/modules', ['crm' => false, 'hr' => false])
            ->assertOk()->assertJsonPath('data.crm', false)->assertJsonPath('data.hr', false)->assertJsonPath('data.accounts', true);

        $this->as($this->advisor)->getJson('/api/v1/auth/me')->assertJsonPath('data.features.crm', false);
    }

    public function test_crm_off_closes_its_screens_webhooks_and_delivery_hook(): void
    {
        [$customer, , $jobId] = $this->job();
        Lead::create(['name' => 'Open lead', 'phone' => '919820700000', 'customer_id' => $customer->id, 'status' => 'contacted', 'kind' => 'new_enquiry']);
        WorkshopSettings::set('modules.crm', false);

        $this->as($this->advisor)->getJson('/api/v1/crm/leads')->assertNotFound()->assertJsonFragment(['message' => 'The CRM & leads module is switched off (Settings › Modules).']);
        $key = LeadSource::where('slug', 'google')->value('webhook_key');
        $this->postJson("/api/v1/public/leads/{$key}", ['name' => 'X', 'phone' => '9820700001'])->assertNotFound();
        $this->artisan('crm:run')->expectsOutput('CRM is switched off.')->assertSuccessful();

        // Delivery doesn't touch CRM
        DB::table('job_cards')->where('id', $jobId)->update(['status' => 'delivered']);
        JobCardStatusHistory::create(['job_card_id' => $jobId, 'from_status' => 'gate_pass_generated', 'to_status' => 'delivered']);
        $this->assertSame('contacted', Lead::firstOrFail()->status);

        // My Day and search leave CRM out
        $keys = collect($this->as($this->advisor)->getJson('/api/v1/my-day')->assertOk()->json('data.cards'))->pluck('key');
        $this->assertNotContains('new_leads', $keys);
        $this->assertNotContains('arrivals', $keys);
    }

    public function test_accounts_off_posts_nothing_and_resync_catches_up(): void
    {
        [$customer, , $jobId] = $this->job();
        WorkshopSettings::set('modules.accounts', false);

        $bill = Invoice::create(['branch_id' => $this->branch->id, 'job_card_id' => $jobId, 'customer_id' => $customer->id, 'kind' => 'tax', 'invoice_number' => 'INV-M-1', 'invoice_date' => today(), 'status' => 'issued']);
        $bill->items()->create(['type' => 'labor', 'description' => 'Service', 'quantity' => 1, 'unit_price' => 1180, 'tax_rate' => 18, 'gst_inclusive' => true]);
        $this->assertSame(0, JournalEntry::count());
        $this->as($this->owner)->getJson('/api/v1/accounts/reports/profit-loss')->assertNotFound();

        WorkshopSettings::set('modules.accounts', true);
        $this->artisan('accounts:resync')->assertSuccessful();
        $this->assertSame(1, JournalEntry::where('source_type', 'invoice')->count());
        $this->assertEquals(-1000.0, (float) DB::table('journal_lines')->where('account_id', Account::where('sub_type', 'sales_services')->value('id'))->sum(DB::raw('debit - credit')));
    }

    public function test_hr_off_hides_attendance_and_staff_collected_money(): void
    {
        [, , $jobId] = $this->job();
        WorkshopSettings::set('modules.hr', false);

        $this->as($this->advisor)->getJson('/api/v1/hr/me/today')->assertNotFound();
        $this->artisan('hr:close-attendance')->expectsOutput('Attendance & payroll is switched off.')->assertSuccessful();

        $this->as($this->advisor)->getJson("/api/v1/workshop/job-cards/{$jobId}/billing")->assertOk()->assertJsonPath('data.staff_method', null);
        $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$jobId}/billing/payments", ['parts' => [
            ['method' => 'staff_collected', 'amount' => 500, 'collected_by' => $this->advisor->id],
        ]])->assertStatus(422);
        // Ordinary payments still work
        $this->as($this->advisor)->postJson("/api/v1/workshop/job-cards/{$jobId}/billing/payments", ['parts' => [['method' => 'cash', 'amount' => 500]]])->assertCreated();

        // An expense can't be paid from staff-held money
        $this->as($this->owner)->postJson('/api/v1/accounts/expenses', [
            'expense_date' => today()->toDateString(), 'account_id' => Account::where('code', '6220')->value('id'), 'paid_to' => 'Pump',
            'amount' => 100, 'paid_via' => 'staff_custody', 'paid_by_user_id' => $this->advisor->id,
        ])->assertStatus(422);
    }
}
