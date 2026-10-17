<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\BookingSlot;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Models\JourneyEnrollment;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\VehicleCategory;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\JobSheetTemplatesSeeder;
use Database\Seeders\OilTypesSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServicePackagesSeeder;
use Database\Seeders\VehicleCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7: leads in (webhook, quick add), round-robin, calls, quotes, bookings → job cards.
 */
class CrmLeadsBookingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $advisor;
    private User $marketing;
    private User $marketing2;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            OrganizationSeeder::class, RolesAndPermissionsSeeder::class, VehicleCategoriesSeeder::class, OilTypesSeeder::class,
            ServicePackagesSeeder::class, JobSheetTemplatesSeeder::class, AdminUserSeeder::class,
        ]);

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        $this->marketing = User::where('email', 'marketing@expressautocare.in')->firstOrFail();
        $this->branch = $this->advisor->branches()->firstOrFail();

        $this->marketing2 = User::create(['name' => 'Marketing Two', 'email' => 'mkt2@example.com', 'phone' => '9111111112', 'password' => Hash::make('x'), 'is_active' => true]);
        $this->marketing2->roles()->attach(Role::where('slug', Role::MARKETING)->value('id'));
        $this->marketing2->branches()->attach($this->branch->id, ['is_primary' => true]);

        // Monday, 11:00 — inside working and sending hours
        $this->travelTo('2026-11-02 11:00');
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branch->id);
    }

    private function webhook(string $slug, array $payload)
    {
        $key = LeadSource::where('slug', $slug)->value('webhook_key');

        return $this->postJson("/api/v1/public/leads/{$key}", $payload);
    }

    private function plan(): ServicePackage
    {
        return ServicePackage::where('slug', 'ea-refresh')->firstOrFail();
    }

    public function test_leads_arrive_by_webhook_in_any_format_and_are_shared_out(): void
    {
        // Generic JSON (website / Pabbly / Zapier)
        $this->webhook('meta', ['full_name' => 'Ravi Shah', 'phone_number' => '+91 98200 11111', 'car_model' => 'Baleno', 'campaign_name' => 'Diwali offer', 'utm_source' => 'fb'])
            ->assertOk()->assertJsonPath('data.new', true);
        // Google Ads lead form
        $this->webhook('google', ['lead_id' => 'G1', 'campaign_id' => '555', 'user_column_data' => [
            ['column_id' => 'FULL_NAME', 'string_value' => 'Neha Rao'],
            ['column_id' => 'PHONE_NUMBER', 'string_value' => '9820022222'],
        ]])->assertOk();
        // Meta Lead Ads field data
        $this->webhook('meta', ['field_data' => [['name' => 'full_name', 'values' => ['Arjun K']], ['name' => 'phone_number', 'values' => ['9820033333']]]])->assertOk();

        $this->postJson('/api/v1/public/leads/wrong-key', ['phone' => '9820044444'])->assertNotFound();
        $this->webhook('meta', ['full_name' => 'No phone'])->assertStatus(422);

        $leads = Lead::with(['campaign', 'source'])->orderBy('id')->get();
        $this->assertCount(3, $leads);
        $this->assertSame('919820011111', $leads[0]->phone);
        $this->assertSame('Diwali offer', $leads[0]->campaign->name);
        $this->assertSame('Baleno', $leads[0]->car_text);
        $this->assertSame('google', $leads[1]->source->slug);

        // Round-robin between the two marketing executives
        $this->assertEqualsCanonicalizing([$this->marketing->id, $this->marketing2->id], [$leads[0]->assigned_to, $leads[1]->assigned_to]);
        $this->assertSame($leads[0]->assigned_to, $leads[2]->assigned_to);
        $this->assertTrue($this->marketing->notifications()->where('data->kind', 'lead_assigned')->exists());

        // First contact due within the SLA; the welcome message waits in "Messages to send"
        $this->assertEquals(now()->addMinutes(15)->toDateTimeString(), $leads[0]->first_contact_due_at->toDateTimeString());
        $welcome = CustomerMessage::where('lead_id', $leads[0]->id)->firstOrFail();
        $this->assertSame('prepared', $welcome->status);
        $this->assertStringContainsString('Ravi Shah', $welcome->body);
        $this->assertStringContainsString('/c/', $welcome->body);

        $queue = $this->as(User::find($leads[0]->assigned_to))->getJson('/api/v1/crm/messages')->assertOk()->json('data');
        $this->assertNotEmpty(collect($queue['rows'])->firstWhere('lead.id', $leads[0]->id)['share_url']);
    }

    public function test_repeat_enquiry_warms_the_open_lead_and_existing_customers_are_recognised(): void
    {
        $customer = Customer::create(['name' => 'Old Customer', 'phone' => '98200 55555']);

        $this->webhook('google', ['name' => 'Someone', 'phone' => '9820055555'])->assertOk();
        $lead = Lead::firstOrFail();
        $this->assertSame($customer->id, $lead->customer_id);
        $score = $lead->score;

        $this->webhook('meta', ['name' => 'Someone', 'phone' => '+919820055555', 'message' => 'AC not cooling'])->assertOk()->assertJsonPath('data.new', false);
        $this->assertSame(1, Lead::count());
        $this->assertGreaterThan($score, $lead->fresh()->score);
        $this->assertSame('AC not cooling', $lead->fresh()->requirement);
        $this->assertTrue($lead->activities()->where('type', 'enquired_again')->exists());
    }

    public function test_staff_on_leave_get_no_leads(): void
    {
        LeaveRequest::create([
            'user_id' => $this->marketing->id, 'branch_id' => $this->branch->id, 'leave_type_id' => LeaveType::where('code', 'CL')->value('id'),
            'from_date' => today(), 'to_date' => today(), 'days' => 1, 'status' => 'approved',
        ]);

        foreach (['9820060001', '9820060002', '9820060003'] as $phone) {
            $this->as($this->advisor)->postJson('/api/v1/crm/leads', ['name' => 'Caller', 'phone' => $phone, 'source' => 'call'])->assertCreated();
        }
        $this->assertSame([$this->marketing2->id], Lead::pluck('assigned_to')->unique()->values()->all());
    }

    public function test_call_outcomes_set_follow_ups_and_closing_needs_a_reason(): void
    {
        $id = $this->as($this->marketing)->postJson('/api/v1/crm/leads', ['name' => 'Kiran', 'phone' => '9820070000', 'source' => 'call', 'assigned_to' => $this->marketing->id])
            ->assertCreated()->json('data.id');
        $lead = Lead::findOrFail($id);
        $me = User::find($lead->assigned_to);

        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'no_answer'])->assertOk();
        $this->assertEquals(now()->addHours(2)->toDateTimeString(), $lead->fresh()->next_follow_up_at->toDateTimeString());
        $this->assertNotNull($lead->fresh()->first_contacted_at);
        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'busy'])->assertOk();
        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'no_answer'])->assertOk()->assertJsonPath('data.status', 'unreachable');

        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'call_back'])->assertStatus(422);
        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'call_back', 'call_back_at' => now()->addDay()->setTime(17, 0)->toDateTimeString()])
            ->assertOk()->assertJsonPath('data.status', 'contacted');

        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'not_interested'])->assertStatus(422);
        $notNow = LostReason::where('nurture', true)->firstOrFail();
        $this->as($me)->postJson("/api/v1/crm/leads/{$id}/call", ['outcome' => 'not_interested', 'lost_reason_id' => $notNow->id])
            ->assertOk()->assertJsonPath('data.status', 'lost');

        // "Not now" keeps a gentle journey going; the new-lead journey stopped
        $journeys = JourneyEnrollment::with('journey')->where('lead_id', $id)->get();
        $this->assertSame('active', $journeys->firstWhere('journey.trigger', 'lost_not_now')->status);
        $this->assertSame('stopped', $journeys->firstWhere('journey.trigger', 'new_lead')->status);
    }

    public function test_quote_uses_the_price_masters(): void
    {
        $id = $this->as($this->marketing)->postJson('/api/v1/crm/leads', ['name' => 'Priya', 'phone' => '9820080000', 'source' => 'call'])->json('data.id');
        $hatch = VehicleCategory::where('slug', 'hatchback')->value('id');

        $res = $this->as($this->marketing)->postJson("/api/v1/crm/leads/{$id}/quote", ['package_id' => $this->plan()->id, 'category_id' => $hatch])->assertOk();
        $body = $res->json('data.message.body');
        $price = (float) $this->plan()->pricing()->where('category_id', $hatch)->min('price');
        $this->assertStringContainsString(number_format($price, 0), $body);
        $this->assertStringContainsString('/c/', $body);
        $this->assertSame('interested', $res->json('data.lead.status'));
    }

    public function test_drive_in_booking_fills_slots_and_opens_the_job_card_on_arrival(): void
    {
        BookingSlot::query()->update(['capacity' => 1]);
        $id = $this->as($this->marketing)->postJson('/api/v1/crm/leads', ['name' => 'Sam', 'phone' => '9820090000', 'source' => 'call', 'car_text' => 'Swift'])->json('data.id');
        $other = $this->as($this->marketing)->postJson('/api/v1/crm/leads', ['name' => 'Tom', 'phone' => '9820090001', 'source' => 'call'])->json('data.id');

        $date = today()->addDay()->toDateString();
        $slots = $this->as($this->advisor)->getJson("/api/v1/crm/booking-availability?date={$date}")->assertOk()->json('data');
        $this->assertSame('09:30', $slots[0]['from']);

        $booking = $this->as($this->advisor)->postJson('/api/v1/crm/bookings', [
            'lead_id' => $id, 'booking_date' => $date, 'slot_from' => '09:30', 'package_id' => $this->plan()->id, 'service_mode' => 'drive_in',
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed')->json('data');

        // The slot is full now
        $this->as($this->advisor)->postJson('/api/v1/crm/bookings', ['lead_id' => $other, 'booking_date' => $date, 'slot_from' => '09:30'])
            ->assertStatus(422)->assertJsonFragment(['slot_from' => ['Full.']]);

        // Booked: the nurturing stops and waiting messages are dropped
        $this->assertSame('booked', Lead::find($id)->status);
        $this->assertSame(0, CustomerMessage::where('lead_id', $id)->where('status', 'prepared')->where('template', 'like', 'crm.journey.%')->count());
        $this->assertTrue(CustomerMessage::where('lead_id', $id)->where('template', 'crm.booking_confirmed')->exists());

        // The car arrives: customer and car are created, the job card form is pre-filled
        $this->travelTo($date . ' 09:40');
        $prefill = $this->as($this->advisor)->postJson("/api/v1/crm/bookings/{$booking['id']}/arrive", [
            'registration_no' => 'mh 04 zz 1234', 'category_id' => VehicleCategory::where('slug', 'hatchback')->value('id'),
        ])->assertOk()->json('data');
        $customer = Customer::findOrFail($prefill['customer_id']);
        $this->assertSame('9820090000', $customer->phone);
        $this->assertSame('other', $customer->source);
        $this->assertSame('MH04ZZ1234', $customer->vehicles()->value('registration_no'));

        $jobId = $this->as($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            ...$prefill,
            'oil_type_id' => OilType::where('slug', 'semi-synthetic')->value('id'),
            'km_reading' => 45000,
            'fuel_level' => 'half',
        ])->assertCreated()->json('data.id');

        $this->assertSame('converted', Booking::find($booking['id'])->status);
        $this->assertSame($jobId, Booking::find($booking['id'])->job_card_id);
        $this->assertSame($customer->id, Lead::find($id)->customer_id);
    }

    public function test_pickup_booking_opens_the_job_card_with_its_trip(): void
    {
        $id = $this->as($this->marketing)->postJson('/api/v1/crm/leads', ['name' => 'Asha', 'phone' => '9820091111', 'source' => 'google', 'registration_no' => 'MH01AB1111'])->json('data.id');

        $this->as($this->advisor)->postJson('/api/v1/crm/bookings', [
            'lead_id' => $id, 'booking_date' => today()->addDay()->toDateString(), 'slot_from' => '11:00',
            'service_mode' => 'pickup_drop', 'service_type' => 'general_repair',
        ])->assertStatus(422);

        $booking = $this->as($this->advisor)->postJson('/api/v1/crm/bookings', [
            'lead_id' => $id, 'booking_date' => today()->addDay()->toDateString(), 'slot_from' => '11:00',
            'service_mode' => 'pickup_drop', 'service_type' => 'general_repair', 'address' => '12 Hill Road, Bandra',
        ])->assertCreated()->json('data');

        $job = JobCard::withoutGlobalScopes()->findOrFail($booking['job_card_id']);
        $this->assertSame('pickup_scheduled', $job->status);
        $this->assertSame('MH01AB1111', $job->vehicle->registration_no);
        $this->assertSame('12 Hill Road, Bandra', JobTrip::where('job_card_id', $job->id)->where('kind', 'pickup')->value('address'));
        $this->assertSame('converted', Booking::find($booking['id'])->status);
    }

    public function test_customer_books_from_their_link_and_a_missed_booking_goes_back_to_nurturing(): void
    {
        $this->webhook('meta', ['name' => 'Self Booker', 'phone' => '9820092222'])->assertOk();
        $lead = Lead::firstOrFail();
        $link = CrmLink::where('lead_id', $lead->id)->where('purpose', 'book')->firstOrFail();
        $token = $link->token();

        $page = $this->getJson("/api/v1/public/c/{$token}")->assertOk()->json('data');
        $this->assertSame('book', $page['purpose']);
        $this->assertNotEmpty($page['plans']);
        $this->assertNotEmpty(collect($page['calendar'])->where('free', '>', 0));
        $this->assertTrue($lead->activities()->where('type', 'link_opened')->exists());

        $date = today()->addDays(2)->toDateString();
        $this->getJson("/api/v1/public/c/{$token}/slots?date={$date}")->assertOk();
        $this->postJson("/api/v1/public/c/{$token}/book", ['booking_date' => $date, 'slot_from' => '09:30', 'service_mode' => 'pickup_drop'])->assertStatus(422);
        $this->postJson("/api/v1/public/c/{$token}/book", ['booking_date' => $date, 'slot_from' => '09:30', 'service_mode' => 'drive_in', 'package_id' => $this->plan()->id])
            ->assertCreated();

        $booking = Booking::firstOrFail();
        $this->assertSame('self', $booking->booked_via);
        $this->assertSame('booked', $booking->status);
        $this->assertTrue(User::find($lead->assigned_to)->notifications()->where('data->kind', 'booking_self')->exists());

        // Not here by slot + 3 h → no-show, the lead is worked again
        $this->travelTo($date . ' 15:30');
        $this->artisan('crm:run')->assertSuccessful();
        $this->assertSame('no_show', $booking->fresh()->status);
        $this->assertSame('interested', $lead->fresh()->status);
        $this->assertTrue(JourneyEnrollment::where('lead_id', $lead->id)->whereHas('journey', fn ($q) => $q->where('trigger', 'no_show'))->exists());

        // Bad token
        $this->getJson('/api/v1/public/c/' . str_repeat('x', 32))->assertNotFound();
    }

    public function test_sla_reminds_then_escalates(): void
    {
        $this->webhook('google', ['name' => 'Slow', 'phone' => '9820093333'])->assertOk();
        $lead = Lead::firstOrFail();
        $assignee = User::find($lead->assigned_to);

        $this->travelTo(now()->addMinutes(20));
        $this->artisan('crm:run')->assertSuccessful();
        $this->assertTrue($assignee->notifications()->where('data->kind', 'lead_sla')->exists());

        $this->travelTo(now()->addMinutes(20));
        $this->artisan('crm:run')->assertSuccessful();
        $this->assertTrue($this->manager->notifications()->where('data->kind', 'lead_sla_escalated')->exists());
    }

    public function test_search_and_my_day(): void
    {
        $this->as($this->marketing)->postJson('/api/v1/crm/leads', ['name' => 'Findable Person', 'phone' => '9820094444', 'source' => 'call', 'assigned_to' => $this->marketing->id]);

        $hits = $this->as($this->manager)->getJson('/api/v1/search?q=Findable')->assertOk()->json('data');
        $this->assertSame('lead', $hits[0]['type']);
        $this->assertNotEmpty($this->as($this->manager)->getJson('/api/v1/search?q=94444')->json('data'));

        $cards = collect($this->as($this->marketing)->getJson('/api/v1/my-day')->assertOk()->json('data.cards'));
        $this->assertGreaterThanOrEqual(1, $cards->firstWhere('key', 'new_leads')['count']);
    }
}
