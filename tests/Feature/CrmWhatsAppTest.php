<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\InteraktOutbox;
use App\Modules\Crm\Models\InteraktWebhookEvent;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\WhatsAppChatMessage;
use App\Modules\Crm\Models\WhatsAppConversation;
use App\Modules\Crm\Services\CrmMessageService;
use App\Modules\Crm\Services\WhatsApp\InteraktSyncService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 11: Interakt inside the CRM — chats come into the inbox and become leads, replies in the
 * 24-hour window, templates after it, quick-reply taps act on leads, template approvals, and
 * contacts / events sent to Interakt.
 */
class CrmWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'aW50ZXJha3Qta2V5LWZvci10ZXN0cw==';

    private User $owner;
    private User $manager;
    private User $marketing;
    private User $advisor;
    /** What the fake Interakt answers (tests change it); ids count up */
    private $interakt;
    private int $nextId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->marketing = User::where('email', 'marketing@expressautocare.in')->firstOrFail();
        $this->advisor = User::where('email', 'advisor@expressautocare.in')->firstOrFail();
        Storage::fake('job_media');
        Http::preventStrayRequests();
        $this->interakt = fn (Request $r) => Http::response(['result' => true, 'message' => 'ok', 'id' => 'wamid-' . (++$this->nextId)], 201);
        Http::fake(fn (Request $r) => ($this->interakt)($r));

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/settings/whatsapp', ['provider' => 'interakt', 'api_key' => self::KEY])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function webhook(array $payload, ?string $signature = null)
    {
        $this->app['auth']->forgetGuards();
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/public/whatsapp/interakt/' . WhatsAppSettings::webhookSecret(), [], [], [],
            array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_INTERAKT_SIGNATURE' => $signature]), $body);
    }

    private function incoming(string $phone, string $text, array $extra = []): array
    {
        return [
            'version' => '1.0',
            'timestamp' => now()->toIso8601String(),
            'type' => 'message_received',
            'data' => [
                'customer' => ['id' => 'c-' . $phone, 'channel_phone_number' => '91' . $phone, 'phone_number' => $phone, 'country_code' => '+91', 'traits' => ['name' => 'Ravi Kumar']],
                'message' => array_merge(['id' => 'in-' . uniqid(), 'chat_message_type' => 'CustomerMessage', 'message_content_type' => 'Text', 'message' => $text, 'media_url' => null], $extra),
            ],
        ];
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $user->branches()->value('branches.id'));
    }

    // ---------------------------------------------------------------- webhook

    public function test_signature_is_checked_when_a_secret_is_set(): void
    {
        WhatsAppSettings::setSigningSecret('examplekey');
        // Interakt's own test vector
        $this->assertTrue(WhatsAppSettings::signatureValid('{"foo":1,"bar":2}', 'sha256=b84783d10ede5bd6ed771e8b16fbe5a7093340159d6e49ec4248350b6ec2c7b4'));

        $payload = $this->incoming('9811100001', 'Hello');
        $this->webhook($payload, 'sha256=deadbeef')->assertStatus(401);
        $this->assertSame(0, WhatsAppConversation::count());

        $this->webhook($payload, 'sha256=' . hash_hmac('sha256', json_encode($payload), 'examplekey'))->assertOk();
        $this->assertSame(1, WhatsAppConversation::count());
    }

    public function test_new_number_becomes_a_lead_and_the_chat_is_in_the_inbox(): void
    {
        $this->webhook($this->incoming('9811100002', 'Hi, what is the price of a full service for my Swift?'))->assertOk();

        $lead = Lead::latest('id')->firstOrFail();
        $this->assertSame('whatsapp', LeadSource::find($lead->lead_source_id)->slug);
        $this->assertSame('Ravi Kumar', $lead->name);
        $this->assertStringContainsString('full service', $lead->requirement);
        $this->assertNotNull($lead->assigned_to);
        $this->assertSame('lead created', InteraktWebhookEvent::latest('id')->value('result'));

        $conv = WhatsAppConversation::firstOrFail();
        $this->assertSame($lead->id, $conv->lead_id);
        $this->assertSame($lead->assigned_to, $conv->assigned_to);
        $this->assertTrue($conv->windowOpen());
        $this->assertSame(1, $conv->unread);

        // The second message is logged on the same lead, no new one
        $this->webhook($this->incoming('9811100002', 'Also AC is not cooling'))->assertOk();
        $this->assertSame(1, Lead::count());
        $this->assertTrue($lead->activities()->where('type', 'reply')->where('body', 'like', '%AC is not cooling%')->exists());
        $this->assertSame(2, $conv->fresh()->unread);
    }

    public function test_click_to_whatsapp_start_text_picks_the_campaign(): void
    {
        $source = LeadSource::where('slug', 'whatsapp_ad')->firstOrFail();
        $campaign = Campaign::create(['lead_source_id' => $source->id, 'name' => 'Monsoon offer', 'is_active' => true, 'whatsapp_start_text' => 'Hi, I want the monsoon check-up offer']);

        $this->webhook($this->incoming('9811100003', 'Hi, I want the monsoon check-up offer!', ['referral' => ['source_url' => 'https://fb.me/x', 'headline' => 'Monsoon']]))->assertOk();

        $lead = Lead::latest('id')->firstOrFail();
        $this->assertSame($campaign->id, $lead->campaign_id);
        $this->assertSame($source->id, $lead->lead_source_id);
        $this->assertNotEmpty(WhatsAppConversation::firstOrFail()->referral);
    }

    public function test_customer_with_an_open_job_goes_to_the_advisor_without_a_lead(): void
    {
        $customer = Customer::create(['name' => 'Meena Shah', 'phone' => '9811100004']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH12XY0004']);
        JobCard::withoutEvents(fn () => JobCard::withoutGlobalScopes()->forceCreate([
            'job_number' => 'JC-T-1', 'branch_id' => $this->advisor->branches()->value('branches.id'), 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'service_advisor_id' => $this->advisor->id, 'status' => 'work_in_progress', 'service_type' => 'general_repair',
        ]));

        $this->webhook($this->incoming('9811100004', 'When will my car be ready?'))->assertOk();

        $this->assertSame(0, Lead::count());
        $conv = WhatsAppConversation::firstOrFail();
        $this->assertSame($this->advisor->id, $conv->assigned_to);
        $this->assertSame($customer->id, $conv->customer_id);
        $this->assertSame(1, $this->advisor->notifications()->where('data->kind', 'whatsapp_message')->count());
    }

    public function test_stop_opts_out(): void
    {
        $customer = Customer::create(['name' => 'Asha Rao', 'phone' => '9811100005']);
        $this->webhook($this->incoming('9811100005', 'STOP'))->assertOk();

        $this->assertNotNull($customer->fresh()->marketing_opt_out_at);
        $this->assertSame('opted out', InteraktWebhookEvent::latest('id')->value('result'));
        $this->assertSame(0, Lead::count());
    }

    // ---------------------------------------------------------------- inbox

    public function test_inbox_is_scoped_and_replies_follow_the_24_hour_window(): void
    {
        $this->webhook($this->incoming('9811100006', 'Hello, need a pickup'))->assertOk();
        $conv = WhatsAppConversation::firstOrFail();
        $owner = User::find($conv->assigned_to);

        // Someone else's chat is hidden from another executive; managers see all
        $other = User::where('email', 'parts@expressautocare.in')->firstOrFail();
        $other->roles()->syncWithoutDetaching(\App\Core\Models\Role::where('slug', 'marketing')->pluck('id'));
        $this->as($other)->getJson("/api/v1/crm/whatsapp/conversations/{$conv->id}")->assertNotFound();
        $this->as($this->manager)->getJson('/api/v1/crm/whatsapp/conversations?filter=all')->assertOk()->assertJsonCount(1, 'data.conversations');

        // Inside the window: a free-form reply with a photo
        $this->as($owner)->getJson('/api/v1/crm/whatsapp/conversations?filter=mine')->assertOk()->assertJsonPath('data.conversations.0.window_open', true);
        $this->as($owner)->post("/api/v1/crm/whatsapp/conversations/{$conv->id}/reply", ['body' => 'Sure, our driver can come at 4 pm', 'file' => UploadedFile::fake()->image('slot.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.kind', 'image');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/public/message/') && $r['type'] === 'Image' && $r['data']['message'] === 'Sure, our driver can come at 4 pm' && str_contains($r['data']['mediaUrl'], '/api/v1/public/wa-media/'));

        // The attachment is reachable for Interakt only with its signature
        $url = WhatsAppChatMessage::where('kind', 'image')->value('media_url');
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $this->get($path)->assertOk();
        $this->get(strtok($path, '?') . '?sig=wrong')->assertNotFound();

        // After 24 hours: free text refused, a template allowed
        $conv->update(['last_inbound_at' => now()->subHours(25)]);
        $this->as($owner)->postJson("/api/v1/crm/whatsapp/conversations/{$conv->id}/reply", ['body' => 'Are you there?'])->assertStatus(422);
        WhatsAppTemplate::create(['key' => 'crm.chat_opener', 'label' => 'Chat opener', 'provider_name' => 'chat_opener_v1', 'language' => 'en', 'body_params' => ['name']]);
        $thread = $this->as($owner)->getJson("/api/v1/crm/whatsapp/conversations/{$conv->id}")->assertOk();
        $this->assertSame('crm.chat_opener', $thread->json('data.templates.0.key'));
        $this->as($owner)->postJson("/api/v1/crm/whatsapp/conversations/{$conv->id}/template", ['key' => 'crm.chat_opener'])->assertOk()->assertJsonPath('data.kind', 'template');
        Http::assertSent(fn (Request $r) => ($r['type'] ?? null) === 'Template' && $r['template']['name'] === 'chat_opener_v1' && $r['template']['bodyValues'] === ['Ravi Kumar']);

        // Ticks arrive by webhook
        $id = WhatsAppChatMessage::where('kind', 'template')->value('provider_message_id');
        $this->webhook(['type' => 'message_api_read', 'data' => ['message' => ['id' => $id]]])->assertOk();
        $this->assertSame('read', WhatsAppChatMessage::where('kind', 'template')->value('status'));
    }

    public function test_template_messages_sent_elsewhere_join_the_chat(): void
    {
        WhatsAppTemplate::create(['key' => 'crm.booking_confirmed', 'label' => 'Booking confirmed', 'provider_name' => 'booking_confirmed_v1', 'language' => 'en', 'body_params' => ['name', 'number', 'date']]);
        $customer = Customer::create(['name' => 'Asha Rao', 'phone' => '9811100007']);
        app(CrmMessageService::class)->toCustomer($customer, null, 'crm.booking_confirmed', 'Hi :name, booking :number on :date', ['number' => 'BK-1', 'date' => 'Mon']);

        $msg = WhatsAppChatMessage::firstOrFail();
        $this->assertSame('template', $msg->kind);
        $this->assertSame('919811100007', $msg->conversation->phone);
        $this->assertNotNull($msg->customer_message_id);
    }

    // ---------------------------------------------------------------- buttons and templates

    public function test_quick_reply_taps_act_on_the_lead(): void
    {
        $this->webhook($this->incoming('9811100008', 'Price for brake pads?'))->assertOk();
        $lead = Lead::firstOrFail();
        WhatsAppTemplate::create(['key' => 'crm.quote', 'label' => 'Quote', 'provider_name' => 'quote_v1', 'language' => 'en', 'body_params' => ['name'],
            'button_actions' => ['Interested' => 'interested', 'Call me' => 'call_me', 'Not now' => 'not_now']]);
        $message = app(CrmMessageService::class)->toLead($lead, 'crm.quote', 'Hi :name, here is the quote');
        $this->assertSame(CustomerMessage::SENT, $message->fresh()->status);

        $tap = fn (string $text) => $this->webhook(['type' => 'message_api_clicked', 'data' => [
            'customer' => ['channel_phone_number' => '919811100008'],
            'message' => ['id' => $message->provider_message_id, 'click_type' => 'QR', 'button_text' => $text, 'meta_data' => ['source_data' => ['callback_data' => (string) $message->id]]],
        ]])->assertOk();

        $tap('Interested');
        $this->assertSame(Lead::INTERESTED, $lead->fresh()->status);
        $this->assertSame('button: interested', InteraktWebhookEvent::latest('id')->value('result'));

        $tap('Call me');
        $this->assertSame(1, User::find($lead->assigned_to)->notifications()->where('data->kind', 'whatsapp_call_me')->count());

        $tap('Not now');
        $this->assertTrue($lead->fresh()->next_follow_up_at->gt(now()->addDays(29)));
        $this->assertSame(3, WhatsAppChatMessage::where('kind', 'button')->count());
    }

    public function test_template_approval_updates_and_fetch_fallback(): void
    {
        WhatsAppTemplate::create(['key' => 'crm.quote', 'label' => 'Quote', 'provider_name' => 'quote_v1', 'language' => 'en', 'body_params' => ['name']]);
        $this->webhook(['type' => 'message_template_status_update', 'data' => ['event' => 'REJECTED', 'message_template_name' => 'quote_v1', 'message_template_language' => 'en', 'reason' => 'INVALID_FORMAT']])->assertOk();
        $this->assertSame('REJECTED', WhatsAppTemplate::where('key', 'crm.quote')->value('approval_status'));
        $this->assertSame(1, $this->owner->notifications()->where('data->kind', 'whatsapp_template')->count());

        // The list endpoint isn't promised by Interakt: when it fails, names are typed in by hand
        $this->interakt = fn (Request $r) => Http::response(['message' => 'Not found'], 404);
        $this->as($this->owner)->postJson('/api/v1/crm/whatsapp/templates/fetch')->assertOk()->assertJsonPath('data.ok', false);

        $this->interakt = fn (Request $r) => Http::response(['results' => ['templates' => [
            ['name' => 'quote_v1', 'language' => 'en', 'category' => 'MARKETING', 'approval_status' => 'APPROVED', 'body' => 'Hi {{1}}', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Interested']]],
        ]]], 200);
        $this->as($this->owner)->postJson('/api/v1/crm/whatsapp/templates/fetch')->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.templates.0.variables', 1);
        $row = WhatsAppTemplate::where('key', 'crm.quote')->first();
        $this->assertSame('APPROVED', $row->approval_status);
        $this->assertSame('Interested', $row->buttons[0]['text']);
    }

    // ---------------------------------------------------------------- contacts and events

    public function test_contacts_and_events_go_to_interakt_with_backoff(): void
    {
        $customer = Customer::create(['name' => "Asha\nRao", 'phone' => '9811100009', 'city' => 'Pune']);
        $this->assertSame(1, InteraktOutbox::where('subject', "customer:{$customer->id}")->count());

        $r = app(InteraktSyncService::class)->drain();
        $this->assertSame(1, $r['sent']);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/v1/public/track/users/') && $req['phoneNumber'] === '9811100009'
            && $req['traits']['name'] === 'Asha Rao' && in_array('Customer', $req['tags'], true));
        $this->assertNotNull($customer->fresh()->interakt_synced_at);

        // A new lead: contact + LeadCreated event; Interakt busy (429) → tried later
        $this->interakt = fn (Request $r) => Http::response(['message' => 'Rate limit exceeded for this resource.'], 429, ['Retry-After' => '45']);
        $this->webhook($this->incoming('9811100010', 'Need a car wash'))->assertOk();
        $this->assertTrue(InteraktOutbox::where('event', 'LeadCreated')->exists());
        $r = app(InteraktSyncService::class)->drain();
        $this->assertSame(0, $r['sent']);
        $row = InteraktOutbox::whereNull('sent_at')->orderBy('id')->first();
        $this->assertTrue($row->next_try_at->gt(now()->addSeconds(30)));

        $this->interakt = fn (Request $r) => Http::response(['result' => true], 200);
        $this->travel(2)->minutes();
        $this->assertSame(0, app(InteraktSyncService::class)->drain()['waiting']);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/track/events/') && $req['event'] === 'LeadCreated');

        // Switched off: nothing is queued
        WorkshopSettings::set('crm.whatsapp.sync_contacts', false);
        WorkshopSettings::set('crm.whatsapp.sync_events', false);
        $before = InteraktOutbox::count();
        Customer::create(['name' => 'Off', 'phone' => '9811100011']);
        $this->assertSame($before, InteraktOutbox::count());
    }

    public function test_crm_off_keeps_stop_only_and_hides_the_inbox(): void
    {
        WorkshopSettings::set('modules.crm', false);
        $customer = Customer::create(['name' => 'Asha Rao', 'phone' => '9811100012']);

        $this->webhook($this->incoming('9811100013', 'Hello'))->assertOk();
        $this->assertSame(0, WhatsAppConversation::count());
        $this->webhook($this->incoming('9811100012', 'stop'))->assertOk();
        $this->assertNotNull($customer->fresh()->marketing_opt_out_at);
        $this->as($this->manager)->getJson('/api/v1/crm/whatsapp/conversations')->assertNotFound();
    }
}
