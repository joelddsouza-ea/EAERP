<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Billing\Models\CustomerCredit;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\Creative;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Models\Feedback;
use App\Modules\Crm\Models\Journey;
use App\Modules\Crm\Models\JourneyEnrollment;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Referral;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\IncentiveRule;
use App\Modules\Hr\Services\IncentiveService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\Vehicle;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7: journeys (timing, sending window, frequency cap, stops), retention at delivery,
 * service due, feedback, referrals, CRM incentives.
 */
class CrmJourneysRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $marketing;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->marketing = User::where('email', 'marketing@expressautocare.in')->firstOrFail();
        $this->branch = $this->marketing->branches()->firstOrFail();
        $this->travelTo('2026-11-02 11:00');
        $this->actingAs($this->marketing);
    }

    private function lead(string $phone = '9820100000'): Lead
    {
        return app(LeadService::class)->capture(['name' => 'Journey Lead', 'phone' => $phone, 'source' => 'meta', 'branch_id' => $this->branch->id])[0];
    }

    private function journeyMessages(Lead $lead)
    {
        return CustomerMessage::where('lead_id', $lead->id)->where('template', 'like', 'crm.journey.%')->orderBy('id')->get();
    }

    /**
     * A delivered job for the customer, with a bill.
     */
    private function deliveredJob(Customer $customer, Vehicle $vehicle, float $total = 5900, array $extra = []): int
    {
        $jobId = DB::table('job_cards')->insertGetId([
            'branch_id' => $this->branch->id, 'job_number' => 'JC-R-' . uniqid(), 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'status' => 'delivered', 'service_type' => 'scheduled', 'km_reading' => 40000, 'created_at' => now(), 'updated_at' => now(), ...$extra,
        ]);
        $bill = Invoice::create(['branch_id' => $this->branch->id, 'job_card_id' => $jobId, 'customer_id' => $customer->id, 'kind' => 'tax', 'invoice_number' => 'INV-R-' . $jobId, 'invoice_date' => today(), 'status' => 'issued']);
        $bill->items()->create(['type' => 'labor', 'description' => 'Service', 'quantity' => 1, 'unit_price' => $total, 'tax_rate' => 18, 'gst_inclusive' => true]);

        return $jobId;
    }

    public function test_new_lead_journey_runs_on_its_days_within_the_sending_window(): void
    {
        // (The frequency cap is tested on its own)
        WorkshopSettings::set('crm.frequency_cap_days', 2);
        $lead = $this->lead();
        $this->assertCount(1, $this->journeyMessages($lead)); // day 0, at once

        // Day 2 step is at 10:30 on day 2; nothing before
        $this->travelTo('2026-11-03 12:00');
        $this->artisan('crm:run');
        $this->assertCount(1, $this->journeyMessages($lead));

        // At night nothing goes out; it waits for the morning
        $this->travelTo('2026-11-04 22:00');
        $this->artisan('crm:run');
        $this->assertCount(1, $this->journeyMessages($lead));
        $this->assertSame('2026-11-05 09:30:00', JourneyEnrollment::where('lead_id', $lead->id)->value('next_run_at')->toDateTimeString());

        $this->travelTo('2026-11-05 09:45');
        $this->artisan('crm:run');
        $messages = $this->journeyMessages($lead);
        $this->assertCount(2, $messages);
        $this->assertSame(Creative::where('title', 'Reminder with offer')->value('id'), $messages[1]->creative_id);
    }

    public function test_frequency_cap_and_opt_out(): void
    {
        WorkshopSettings::set('crm.frequency_cap_days', 5);
        $lead = $this->lead();

        // Day 2 is within 5 days of the welcome: pushed a day
        $this->travelTo('2026-11-04 11:00');
        $this->artisan('crm:run');
        $this->assertCount(1, $this->journeyMessages($lead));

        // Opt out: the journey stops and waiting messages are dropped
        $this->postJson('/api/v1/public/c/' . CrmLink::where('lead_id', $lead->id)->where('purpose', 'book')->firstOrFail()->token() . '/stop')->assertOk();
        $this->assertNotNull($lead->fresh()->opted_out_at);
        $this->assertSame('stopped', JourneyEnrollment::where('lead_id', $lead->id)->value('status'));
        $this->assertSame(0, CustomerMessage::where('lead_id', $lead->id)->where('status', 'prepared')->count());
    }

    public function test_creative_with_a_file_is_sent_as_a_link_to_view_it(): void
    {
        $creative = Creative::where('title', 'Welcome + plans')->firstOrFail();
        \Illuminate\Support\Facades\Storage::fake('job_media');
        \Illuminate\Support\Facades\Storage::disk('job_media')->put('creatives/plan.jpg', 'img');
        $creative->update(['kind' => 'image', 'file_disk' => 'job_media', 'file_path' => 'creatives/plan.jpg', 'mime' => 'image/jpeg']);

        $lead = $this->lead();
        $message = $this->journeyMessages($lead)->first();
        $this->assertNotNull($message->media_url);
        $view = CrmLink::where('lead_id', $lead->id)->where('purpose', 'creative')->firstOrFail();
        $this->assertStringContainsString($view->url(), $message->body);
        $this->get('/api/v1/public/c/' . $view->token() . '/media')->assertOk();
    }

    public function test_delivery_wins_the_lead_starts_feedback_and_rewards_the_referrer(): void
    {
        $referrer = Customer::create(['name' => 'Referrer', 'phone' => '9820200000', 'referral_code' => 'REF123']);
        [$lead] = app(LeadService::class)->capture(['name' => 'Friend', 'phone' => '9820200001', 'source' => 'referral', 'referral_code' => 'REF123', 'branch_id' => $this->branch->id]);
        $this->assertSame($referrer->id, $lead->referred_by_customer_id);

        $customer = Customer::create(['name' => 'Friend', 'phone' => '9820200001', 'lead_id' => $lead->id]);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH02XY0001']);
        $lead->update(['customer_id' => $customer->id]);
        $jobId = $this->deliveredJob($customer, $vehicle, 5900);
        Booking::create(['branch_id' => $this->branch->id, 'number' => 'BK-T-1', 'lead_id' => $lead->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'booking_date' => today(), 'status' => 'converted', 'job_card_id' => $jobId]);

        app(RetentionService::class)->onJobStatus($jobId, 'delivered');

        $lead->refresh();
        $this->assertSame('won', $lead->status);
        $this->assertEquals(5900, $lead->won_revenue);

        // Thank-you + feedback the next day
        $this->assertTrue(JourneyEnrollment::where('customer_id', $customer->id)->whereHas('journey', fn ($q) => $q->where('trigger', 'after_delivery'))->exists());
        $this->travelTo('2026-11-03 10:45');
        $this->artisan('crm:run');
        $thanks = CustomerMessage::where('customer_id', $customer->id)->where('template', 'like', 'crm.journey.%')->latest('id')->firstOrFail();
        $this->assertStringContainsString('/c/', $thanks->body);

        // Referral reward as customer credit, booked as a marketing cost
        $this->assertSame('rewarded', Referral::firstOrFail()->status);
        $this->assertEquals(500, CustomerCredit::balanceFor($referrer->id));
        $this->assertTrue(JournalEntry::where('source_type', 'referral')->exists());

        // Feedback: low rating alerts the manager, a good one is asked for a review
        $feedback = CrmLink::where('purpose', 'feedback')->where('job_card_id', $jobId)->firstOrFail();
        $this->postJson('/api/v1/public/c/' . $feedback->token() . '/feedback', ['rating' => 2, 'comments' => 'Took too long'])->assertOk()->assertJsonPath('data.review_url', null);
        $this->assertTrue($this->manager->notifications()->where('data->kind', 'feedback_low')->exists());
        $this->assertSame(2, Feedback::firstOrFail()->rating);

        WorkshopSettings::set('crm.google_review_url', 'https://g.page/r/express-autocare/review');
        $this->postJson('/api/v1/public/c/' . $feedback->token() . '/feedback', ['rating' => 5])->assertOk()->assertJsonPath('data.review_url', 'https://g.page/r/express-autocare/review');
    }

    public function test_service_due_journey_from_the_vehicle_due_date(): void
    {
        $customer = Customer::create(['name' => 'Due Customer', 'phone' => '9820300000']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'registration_no' => 'MH03DU0001',
            'last_service_at' => today()->subMonths(9), 'last_service_km' => 30000,
            'next_service_due_date' => today()->addDays(21), 'next_service_due_km' => 37500,
        ]);

        $this->artisan('crm:daily')->assertSuccessful();
        $enrollment = JourneyEnrollment::where('vehicle_id', $vehicle->id)->firstOrFail();
        $this->assertSame(today()->addDays(21)->toDateString(), $enrollment->anchor_date->toDateString());

        // Day −21 is today at 10:30 (already past at 11:00): it runs now
        $this->artisan('crm:run');
        $due = CustomerMessage::where('customer_id', $customer->id)->firstOrFail();
        $this->assertStringContainsString(today()->addDays(21)->format('d M Y'), $due->body);

        // Enrolled once only
        $this->artisan('crm:daily');
        $this->assertSame(1, JourneyEnrollment::where('vehicle_id', $vehicle->id)->count());

        // Booked → it stops
        Booking::create(['branch_id' => $this->branch->id, 'number' => 'BK-T-2', 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'booking_date' => today()->addDays(3), 'status' => 'confirmed']);
        $this->travelTo(today()->addDays(14)->setTime(11, 0));
        $this->artisan('crm:run');
        $this->assertSame('stopped', $enrollment->fresh()->status);
    }

    public function test_pending_work_journey_after_delivery_and_lead_incentive(): void
    {
        $customer = Customer::create(['name' => 'Pending', 'phone' => '9820400000']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH04PW0001']);
        $jobId = $this->deliveredJob($customer, $vehicle);
        $estimateId = DB::table('job_card_estimates')->insertGetId(['job_card_id' => $jobId, 'version' => 1, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_card_estimate_lines')->insert([
            'estimate_id' => $estimateId, 'line_key' => (string) \Illuminate\Support\Str::uuid(), 'section' => 'additional', 'kind' => 'part', 'description' => 'Brake pads',
            'quantity' => 1, 'rate' => 2000, 'amount' => 2000, 'approval_status' => 'declined', 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$lead] = app(LeadService::class)->capture(['name' => 'Pending', 'phone' => '9820400000', 'source' => 'call', 'branch_id' => $this->branch->id, 'assigned_to' => $this->marketing->id]);
        app(RetentionService::class)->onJobStatus($jobId, 'delivered');

        $this->assertTrue(JourneyEnrollment::where('customer_id', $customer->id)->whereHas('journey', fn ($q) => $q->where('trigger', 'pending_work'))->exists());
        $this->assertSame('won', $lead->fresh()->status);

        // Rs 200 per converted lead for marketing executives
        EmployeeProfile::create(['user_id' => $this->marketing->id, 'branch_id' => $this->branch->id, 'monthly_salary' => 20000]);
        IncentiveRule::create(['name' => 'Lead bonus', 'roles' => ['marketing'], 'basis' => 'per_lead_converted', 'value' => 200, 'is_active' => true]);
        $earned = app(IncentiveService::class)->forPeriod('2026-11');
        $this->assertEquals(200, $earned[$this->marketing->id][0]['amount']);
    }

    public function test_customer_timeline_and_insights(): void
    {
        $lead = $this->lead('9820500000');
        Sanctum::actingAs($this->owner);
        $customer = Customer::create(['name' => 'Timeline', 'phone' => '9820500000']);
        $lead->update(['customer_id' => $customer->id]);

        $this->withHeader('X-Branch-Id', (string) $this->branch->id)->getJson("/api/v1/crm/customers/{$customer->id}/timeline")
            ->assertOk()->assertJsonPath('data.summary.open_lead_id', $lead->id);

        $data = $this->withHeader('X-Branch-Id', (string) $this->branch->id)->getJson('/api/v1/crm/insights')->assertOk()->json('data');
        $this->assertSame(1, $data['funnel']['leads']);
        $this->assertSame('Meta ads (Facebook / Instagram)', $data['by_source'][0]['name']);
    }
}
