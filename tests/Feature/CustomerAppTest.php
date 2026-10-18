<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Models\Payment;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Models\Lead;
use App\Modules\CustomerApp\Models\AiExplanation;
use App\Modules\CustomerApp\Models\AiRequest;
use App\Modules\CustomerApp\Models\CustomerNotification;
use App\Modules\CustomerApp\Models\PaymentClaim;
use App\Modules\CustomerApp\Services\Ai\AiSettings;
use App\Modules\CustomerApp\Services\Ai\ClaudeClient;
use App\Modules\Hr\Models\StaffLiveStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Services\CustomerLinkService;
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
use Tests\TestCase;

/**
 * Phase 9: the customer app — sign-in, jobs, live driver, UPI claims, bookings, inbox,
 * documents, wallet and the AI assistant.
 */
class CustomerAppTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $advisor;
    private User $backoffice;
    private User $driver;
    private int $branchId;
    private Customer $customer;
    private Vehicle $vehicle;

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
        $this->driver = User::where('email', 'driver@expressautocare.in')->firstOrFail();
        $this->branchId = $this->advisor->branches()->firstOrFail()->id;

        $this->customer = Customer::create(['name' => 'Meera Kulkarni', 'phone' => '98200 11223']);
        $this->vehicle = Vehicle::create([
            'customer_id' => $this->customer->id,
            'registration_no' => 'MH12AB1234',
            'category_id' => VehicleCategory::where('slug', 'hatchback')->value('id'),
            'fuel_type' => 'petrol',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function staff(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branchId);
    }

    private function newJob(): JobCard
    {
        $id = $this->staff($this->advisor)->postJson('/api/v1/workshop/job-cards', [
            'service_mode' => 'drive_in',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'package_id' => ServicePackage::where('slug', 'ea-refresh')->value('id'),
            'oil_type_id' => OilType::where('slug', 'semi-synthetic')->value('id'),
            'service_type' => 'scheduled',
            'km_reading' => 42000,
            'fuel_level' => 'half',
        ])->assertCreated()->json('data.id');

        return JobCard::withoutGlobalScopes()->findOrFail($id);
    }

    /** A customer session token, signed in with the job's WhatsApp link */
    private function signIn(?JobCard $job = null): string
    {
        $job ??= $this->newJob();
        $token = app(CustomerLinkService::class)->ensure($job)->token;
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/customer/auth/link', ['kind' => 'job', 'token' => $token])->assertOk()->json('data.token');
    }

    private function app(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Branch-Id' => '']);
    }

    // ---------------------------------------------------------------- sign in

    public function test_sign_in_with_a_job_link_and_see_home(): void
    {
        $job = $this->newJob();
        $token = $this->signIn($job);

        $home = $this->app($token)->getJson('/api/v1/customer/home')->assertOk();
        $home->assertJsonPath('data.customer.name', 'Meera Kulkarni');
        $this->assertSame($job->id, $home->json('data.active_jobs.0.id'));
        $this->assertSame('MH12AB1234', $home->json('data.vehicles.0.registration_no'));

        // A customer token never opens staff screens; a staff login never opens the customer app
        $this->app($token)->getJson('/api/v1/workshop/job-cards')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->advisor);
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/customer/home')->assertUnauthorized();

        // Signing out ends the session
        $this->app($token)->postJson('/api/v1/customer/auth/logout')->assertOk();
        $this->app($token)->getJson('/api/v1/customer/home')->assertUnauthorized();

        $this->postJson('/api/v1/customer/auth/link', ['kind' => 'job', 'token' => str_repeat('x', 40)])->assertNotFound();
    }

    public function test_sign_in_with_a_crm_link(): void
    {
        $link = CrmLink::issue(['purpose' => CrmLink::BOOK, 'customer_id' => $this->customer->id, 'expires_at' => now()->addDays(10)]);

        $this->postJson('/api/v1/customer/auth/link', ['kind' => 'crm', 'token' => $link->token()])
            ->assertOk()->assertJsonPath('data.customer.id', $this->customer->id);
    }

    public function test_sign_in_with_a_code_read_out_by_staff(): void
    {
        $this->newJob();

        // Unknown numbers get the same answer
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '9000000009'])->assertOk()->assertJsonPath('data.via', 'staff');

        $this->postJson('/api/v1/customer/auth/code', ['phone' => '+91 98200 11223'])->assertOk()->assertJsonPath('data.via', 'staff');

        // The advisor of the latest job gets a bell, and sees the code on the customer's page
        $this->assertSame(1, $this->advisor->notifications()->where('data->kind', 'app_sign_in_code')->count());
        $code = $this->staff($this->advisor)->getJson("/api/v1/customer-app/customers/{$this->customer->id}/sign-in-code")->assertOk()->json('data.code.code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '9820011223', 'code' => '000000'])->assertStatus(422);
        $token = $this->postJson('/api/v1/customer/auth/verify', ['phone' => '9820011223', 'code' => $code])->assertOk()->json('data.token');
        $this->app($token)->getJson('/api/v1/customer/home')->assertOk();

        // Used once
        $this->postJson('/api/v1/customer/auth/verify', ['phone' => '9820011223', 'code' => $code])->assertStatus(422);
    }

    public function test_customer_app_switched_off(): void
    {
        WorkshopSettings::set('modules.customer_app', false);
        $this->postJson('/api/v1/customer/auth/code', ['phone' => '9820011223'])->assertNotFound();
    }

    // ---------------------------------------------------------------- jobs, driver, payments

    public function test_job_screen_reuses_the_job_page_and_never_shows_others_jobs(): void
    {
        $job = $this->newJob();
        $token = $this->signIn($job);

        $screen = $this->app($token)->getJson("/api/v1/customer/jobs/{$job->id}")->assertOk();
        $pageToken = $screen->json('data.page_token');
        $this->getJson("/api/v1/public/j/{$pageToken}")->assertOk()->assertJsonPath('data.job.job_number', $job->job_number);

        $other = Customer::create(['name' => 'Someone Else', 'phone' => '9811111111']);
        $otherJob = JobCard::withoutGlobalScopes()->create([
            'branch_id' => $this->branchId, 'job_number' => 'JC-X-1', 'customer_id' => $other->id,
            'vehicle_id' => Vehicle::create(['customer_id' => $other->id, 'registration_no' => 'MH01ZZ0001'])->id,
            'status' => 'intake_pending', 'service_type' => 'general_repair',
        ]);
        $this->app($token)->getJson("/api/v1/customer/jobs/{$otherJob->id}")->assertNotFound();
        $this->app($token)->getJson("/api/v1/customer/vehicles/{$otherJob->vehicle_id}")->assertNotFound();
    }

    public function test_driver_position_shows_only_while_on_the_way_and_fresh(): void
    {
        $job = $this->newJob();
        $token = $this->signIn($job);
        $trip = JobTrip::create([
            'branch_id' => $this->branchId, 'job_card_id' => $job->id, 'kind' => 'drop', 'status' => JobTrip::SCHEDULED,
            'assigned_to' => $this->driver->id, 'lat' => 18.5600, 'lng' => 73.8000, 'slot_date' => today()->toDateString(),
        ]);
        StaffLiveStatus::create(['user_id' => $this->driver->id, 'lat' => 18.5300, 'lng' => 73.8000, 'recorded_at' => now()->subMinutes(2), 'state' => StaffLiveStatus::ACTIVE]);

        $this->assertNull($this->app($token)->getJson("/api/v1/customer/jobs/{$job->id}")->json('data.driver'));

        $trip->update(['status' => JobTrip::EN_ROUTE]);
        $driver = $this->app($token)->getJson("/api/v1/customer/jobs/{$job->id}")->json('data.driver');
        $this->assertEqualsWithDelta(18.53, $driver['lat'], 0.0001);
        $this->assertEqualsWithDelta(3.3, $driver['distance_km'], 0.2);
        $this->assertGreaterThan(0, $driver['eta_minutes']);

        // An old position isn't shown
        StaffLiveStatus::where('user_id', $this->driver->id)->update(['recorded_at' => now()->subMinutes(30)]);
        $this->assertNull($this->app($token)->getJson("/api/v1/customer/jobs/{$job->id}")->json('data.driver.lat'));
    }

    public function test_upi_payment_claim_confirmed_or_rejected_by_back_office(): void
    {
        WorkshopSettings::set('customer_app.upi_id', 'expressautocare@okicici');
        $job = $this->newJob();
        $token = $this->signIn($job);

        $this->app($token)->getJson("/api/v1/customer/jobs/{$job->id}")->assertJsonPath('data.payment.upi_id', 'expressautocare@okicici');

        $claimId = $this->app($token)->postJson("/api/v1/customer/jobs/{$job->id}/payment-claims", ['amount' => 1500, 'utr' => '4123 5678 9012'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->app($token)->postJson("/api/v1/customer/jobs/{$job->id}/payment-claims", ['amount' => 100, 'utr' => '412356789012'])->assertStatus(422);
        $this->assertSame(1, $this->backoffice->notifications()->where('data->kind', 'upi_claim')->count());

        $this->staff($this->backoffice)->getJson('/api/v1/customer-app/upi-claims')->assertOk()->assertJsonPath('data.pending', 1);
        $this->staff($this->backoffice)->postJson("/api/v1/customer-app/upi-claims/{$claimId}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');

        $payment = Payment::withoutGlobalScopes()->where('job_card_id', $job->id)->firstOrFail();
        $this->assertSame(1500.0, (float) $payment->amount);
        $this->assertSame('412356789012', $payment->parts()->value('reference_number'));
        $this->assertSame(1, CustomerNotification::where('customer_id', $this->customer->id)->where('kind', 'payment')->count());

        // A second one is rejected with a reason the customer sees
        $second = $this->app($token)->postJson("/api/v1/customer/jobs/{$job->id}/payment-claims", ['amount' => 200, 'utr' => '999988887777'])->json('data.id');
        $this->staff($this->backoffice)->postJson("/api/v1/customer-app/upi-claims/{$second}/reject", [])->assertStatus(422);
        $this->staff($this->backoffice)->postJson("/api/v1/customer-app/upi-claims/{$second}/reject", ['reason' => 'Not in the bank statement'])->assertOk();
        $this->assertSame(PaymentClaim::REJECTED, PaymentClaim::find($second)->status);
        $this->staff($this->backoffice)->postJson("/api/v1/customer-app/upi-claims/{$second}/confirm")->assertStatus(422);
    }

    public function test_stage_changes_reach_the_inbox_once(): void
    {
        $job = $this->newJob();
        $token = $this->signIn($job);

        foreach (['ready_for_delivery', 'ready_for_delivery'] as $status) {
            JobCardStatusHistory::create(['job_card_id' => $job->id, 'from_status' => $job->status, 'to_status' => $status, 'changed_by' => $this->advisor->id]);
        }

        $inbox = $this->app($token)->getJson('/api/v1/customer/notifications')->assertOk()->json('data');
        $ready = array_values(array_filter($inbox, fn ($n) => str_contains($n['title'], 'MH12AB1234') && $n['link'] === "/app/jobs/{$job->id}" && $n['kind'] === 'stage' && str_contains((string) $n['body'], 'ready')));
        $this->assertCount(1, $ready);

        $this->app($token)->postJson('/api/v1/customer/notifications/read')->assertOk();
        $this->assertSame(0, $this->app($token)->getJson('/api/v1/customer/home')->json('data.unread'));
    }

    // ---------------------------------------------------------------- booking, documents, wallet, reminders

    public function test_book_with_this_cars_prices(): void
    {
        $token = $this->signIn();

        $options = $this->app($token)->getJson("/api/v1/customer/booking-options?vehicle_id={$this->vehicle->id}")->assertOk();
        $plan = collect($options->json('data.plans'))->firstWhere('name', ServicePackage::where('slug', 'ea-refresh')->value('name'));
        $this->assertTrue($plan['exact']);
        $this->assertNotEmpty($plan['oils']);

        $date = today()->addDays(2);
        while ($date->isSunday()) {
            $date->addDay();
        }
        $slot = collect($this->app($token)->getJson("/api/v1/customer/slots?branch_id={$this->branchId}&date={$date->toDateString()}")->json('data'))->firstWhere('free', '>', 0);

        $this->app($token)->postJson('/api/v1/customer/bookings', [
            'vehicle_id' => $this->vehicle->id,
            'branch_id' => $this->branchId,
            'booking_date' => $date->toDateString(),
            'slot_from' => $slot['from'] ?? null,
            'package_id' => $plan['id'],
            'oil_type_id' => $plan['oils'][0]['id'],
            'service_mode' => 'pickup_drop',
        ])->assertStatus(422);

        $number = $this->app($token)->postJson('/api/v1/customer/bookings', [
            'vehicle_id' => $this->vehicle->id,
            'branch_id' => $this->branchId,
            'booking_date' => $date->toDateString(),
            'slot_from' => $slot['from'] ?? null,
            'package_id' => $plan['id'],
            'oil_type_id' => $plan['oils'][0]['id'],
            'service_mode' => 'pickup_drop',
            'address' => 'Flat 4, Baner Road, Pune',
        ])->assertOk()->json('data.number');

        $booking = Booking::where('number', $number)->firstOrFail();
        $this->assertSame('app', $booking->booked_via);
        $this->assertSame($this->customer->id, $booking->customer_id);

        $list = $this->app($token)->getJson('/api/v1/customer/bookings')->assertOk()->json('data');
        $this->assertTrue($list[0]['can_cancel']);
        $this->app($token)->postJson("/api/v1/customer/bookings/{$booking->id}/cancel")->assertOk();
        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
    }

    public function test_documents_update_the_car_and_reminders_are_written_once(): void
    {
        $token = $this->signIn();
        $expiry = today()->addDays(20)->toDateString();

        $doc = $this->app($token)->post('/api/v1/customer/documents', [
            'type' => 'insurance',
            'vehicle_id' => $this->vehicle->id,
            'number' => 'POL-778899',
            'expiry_date' => $expiry,
            'file' => UploadedFile::fake()->create('policy.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame($expiry, $this->vehicle->fresh()->insurance_expiry->toDateString());

        // A PUC certificate sets the car's PUC date
        $this->app($token)->post('/api/v1/customer/documents', [
            'type' => 'puc', 'vehicle_id' => $this->vehicle->id, 'expiry_date' => today()->addMonths(6)->toDateString(),
            'file' => UploadedFile::fake()->image('puc.jpg', 600, 800),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(today()->addMonths(6)->toDateString(), $this->vehicle->fresh()->puc_expiry->toDateString());
        $this->get($doc['url'])->assertOk();

        $this->vehicle->update(['next_service_due_date' => today()->addDays(5), 'last_service_at' => today()->subMonths(5)]);

        $this->artisan('customerapp:reminders')->assertSuccessful();
        $this->artisan('customerapp:reminders')->assertSuccessful();

        $kinds = CustomerNotification::where('customer_id', $this->customer->id)->pluck('kind')->countBy();
        $this->assertSame(1, $kinds['service_due'] ?? 0);
        $this->assertSame(1, $kinds['insurance_due'] ?? 0);
        $this->assertSame(1, $kinds['document_due'] ?? 0);
    }

    public function test_wallet_shows_credit_and_referral(): void
    {
        $token = $this->signIn();
        \App\Modules\Billing\Models\CustomerCredit::create(['branch_id' => $this->branchId, 'customer_id' => $this->customer->id, 'amount' => 500, 'note' => 'Referral reward']);

        $wallet = $this->app($token)->getJson('/api/v1/customer/wallet')->assertOk();
        $this->assertSame(500.0, (float) $wallet->json('data.balance'));
        $this->assertNotEmpty($wallet->json('data.referral.code'));
        $this->assertStringContainsString('/c/', $wallet->json('data.referral.url'));
    }

    // ---------------------------------------------------------------- AI assistant

    private function fakeClaude(array $answer): object
    {
        $fake = new class($answer) implements ClaudeClient {
            public array $calls = [];

            public function __construct(public array $answer) {}

            public function json(string $model, array $system, array $content, array $schema, int $maxTokens = 4000): array
            {
                $this->calls[] = compact('model', 'system', 'content', 'schema');

                return ['data' => $this->answer, 'stop_reason' => 'end_turn', 'usage' => ['input' => 2000, 'output' => 500, 'cache_read' => 0, 'cache_write' => 1500], 'model' => $model, 'error' => null];
            }
        };
        $this->app->instance(ClaudeClient::class, $fake);

        return $fake;
    }

    public function test_assistant_answers_from_the_price_list_with_limits(): void
    {
        $token = $this->signIn();
        $answer = [
            'summary' => 'Likely worn brake pads.', 'urgency' => 'book_soon', 'urgency_advice' => 'Book this week.',
            'likely_causes' => [['cause' => 'Worn pads', 'why' => 'Squeal when braking']],
            'suggested_services' => [['name' => 'Brake service', 'price_min' => 800, 'price_max' => 1200, 'note' => 'Parts extra']],
            'questions_to_check' => [], 'needs_inspection' => true,
        ];
        $fake = $this->fakeClaude($answer);

        // Off until the owner adds a key and switches it on
        $this->app($token)->postJson('/api/v1/customer/assistant/diagnose', ['question' => 'Squeaking noise when I brake'])->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/customer-app/ai-settings', ['enabled' => true])->assertStatus(422);
        $this->putJson('/api/v1/customer-app/ai-settings', ['enabled' => true, 'api_key' => 'sk-ant-test-key-1234', 'daily_limit' => 2])->assertOk()
            ->assertJsonPath('data.api_key_masked', '••••••••1234');
        $this->assertSame('sk-ant-test-key-1234', AiSettings::apiKey());

        $res = $this->app($token)->post('/api/v1/customer/assistant/diagnose', [
            'question' => 'Squeaking noise when I brake',
            'vehicle_id' => $this->vehicle->id,
            'photos' => [UploadedFile::fake()->image('pad.jpg', 2400, 1800)],
        ], ['Accept' => 'application/json'])->assertOk();
        $res->assertJsonPath('data.answer.urgency', 'book_soon');

        // Grounded on the workshop's plans, cached system prompt, photo shrunk and sent
        $call = $fake->calls[0];
        $this->assertStringContainsString(ServicePackage::where('slug', 'ea-refresh')->value('name'), $call['system'][1]['text']);
        $this->assertSame(['type' => 'ephemeral'], $call['system'][1]['cacheControl']);
        $this->assertSame('image', $call['content'][0]['type']);
        $this->assertStringContainsString('Car type: Hatchback', $call['content'][1]['text']);
        $this->assertGreaterThan(0, (float) AiRequest::first()->cost_inr);

        $this->app($token)->postJson('/api/v1/customer/assistant/diagnose', ['question' => 'Another question here'])->assertOk();
        $this->app($token)->postJson('/api/v1/customer/assistant/diagnose', ['question' => 'Third question today'])->assertStatus(429);

        // Over the month's budget: paused
        WorkshopSettings::set('ai.daily_limit', 50);
        WorkshopSettings::set('ai.monthly_budget_inr', 1);
        $this->app($token)->postJson('/api/v1/customer/assistant/diagnose', ['question' => 'Over budget question'])->assertStatus(503);
    }

    public function test_accident_help_creates_a_body_shop_lead(): void
    {
        $token = $this->signIn();
        AiSettings::setApiKey('sk-ant-test');
        WorkshopSettings::set('ai.enabled', true);
        $this->fakeClaude([
            'summary' => 'Front bumper dented, headlamp cracked.', 'damage' => ['Front bumper', 'Left headlamp'],
            'estimate_min' => null, 'estimate_max' => null, 'estimate_note' => 'Quoted after inspection.',
            'safe_to_drive' => 'check_first', 'claim_advice' => 'A claim is likely worth it.', 'claim_steps' => ['Inform the insurer'], 'documents_needed' => ['RC', 'Policy'],
        ]);

        $id = $this->app($token)->post('/api/v1/customer/assistant/accident', [
            'question' => 'Someone hit my car in the parking',
            'vehicle_id' => $this->vehicle->id,
            'photos' => [UploadedFile::fake()->image('damage.jpg', 1200, 900)],
        ], ['Accept' => 'application/json'])->assertOk()->json('data.id');

        $this->app($token)->postJson("/api/v1/customer/assistant/{$id}/inspection")->assertOk();
        $lead = Lead::where('customer_id', $this->customer->id)->firstOrFail();
        $this->assertSame('body_shop', $lead->interest);
        $this->assertSame('lead', AiRequest::find($id)->outcome);
    }

    public function test_estimate_line_explanation_is_written_once(): void
    {
        $job = $this->newJob();
        $token = $this->signIn($job);
        AiSettings::setApiKey('sk-ant-test');
        WorkshopSettings::set('ai.enabled', true);
        $fake = $this->fakeClaude(['explanation' => 'Brake pads wear down with use.']);

        $estimate = \App\Modules\Workshop\Models\JobCardEstimate::forceCreate([
            'job_card_id' => $job->id, 'version' => 1, 'status' => \App\Modules\Workshop\Models\JobCardEstimate::STATUS_SENT,
        ]);
        $line = \App\Modules\Workshop\Models\JobCardEstimateLine::forceCreate([
            'estimate_id' => $estimate->id, 'line_key' => (string) \Illuminate\Support\Str::uuid(), 'section' => 'additional',
            'kind' => 'part', 'description' => 'Front brake pads', 'quantity' => 1, 'rate' => 1800, 'amount' => 1800,
            'approval_status' => 'pending',
        ]);

        foreach ([1, 2] as $i) {
            $this->app($token)->postJson("/api/v1/customer/jobs/{$job->id}/explain", ['line_key' => $line->line_key])
                ->assertOk()->assertJsonPath('data.text', 'Brake pads wear down with use.');
        }
        $this->assertCount(1, $fake->calls);
        $this->assertSame(1, AiExplanation::count());
    }
}
