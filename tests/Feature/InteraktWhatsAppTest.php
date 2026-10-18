<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Models\WhatsAppTemplate;
use App\Core\Services\WhatsApp\WhatsAppSettings;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Services\CrmMessageService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8: WhatsApp Business API through Interakt, off by default; share links stay the fallback.
 */
class InteraktWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'aW50ZXJha3Qta2V5LWZvci10ZXN0cw==';
    private const TEXT = "Hi :name, your booking :number is confirmed for :date.\nSee you!";

    private User $owner;
    private User $manager;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->customer = Customer::create(['name' => 'Asha Rao', 'phone' => '9876543210']);
        Http::preventStrayRequests();
    }

    private function message(array $options = []): CustomerMessage
    {
        return app(CrmMessageService::class)->toCustomer($this->customer, null, 'crm.booking_confirmed', self::TEXT, ['number' => 'BK-7', 'date' => 'Mon, 20 Oct'], null, null, $options);
    }

    private function switchOn(): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/settings/whatsapp', ['provider' => 'interakt', 'api_key' => self::KEY])->assertOk();
    }

    private function map(string $key = 'crm.booking_confirmed', array $params = ['name', 'number', 'date']): void
    {
        WhatsAppTemplate::create(['key' => $key, 'label' => 'Booking confirmed', 'provider_name' => 'booking_confirmed_v1', 'language' => 'en', 'body_params' => $params]);
    }

    public function test_off_by_default_messages_wait_for_the_share_link(): void
    {
        $this->assertSame('share', WhatsAppSettings::provider());

        $message = $this->message();

        $this->assertSame(CustomerMessage::PREPARED, $message->status);
        $this->assertSame('whatsapp_share', $message->channel);
        $this->assertNull($message->error);
        $this->assertSame('BK-7', $message->params['number']);
        Http::assertNothingSent();
    }

    public function test_only_the_owner_sets_it_up_and_the_key_is_kept_secret(): void
    {
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v1/settings/whatsapp')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/settings/whatsapp', ['provider' => 'interakt'])->assertStatus(422);

        $res = $this->putJson('/api/v1/settings/whatsapp', ['provider' => 'interakt', 'api_key' => self::KEY])->assertOk();
        $res->assertJsonPath('data.api_on', true)->assertJsonPath('data.api_key_masked', '••••••••' . substr(self::KEY, -4));
        $this->assertStringNotContainsString(self::KEY, $res->getContent());
        $this->assertNotSame(self::KEY, WorkshopSettings::get('whatsapp.interakt.api_key'));
        $this->assertSame(self::KEY, WhatsAppSettings::apiKey());

        // Every kind of message is listed with the text to submit to Interakt
        $rows = collect($this->getJson('/api/v1/settings/whatsapp/templates')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(['name', 'number', 'workshop', 'date', 'slot', 'mode_line'], array_slice($rows['crm.booking_confirmed']['suggested_params'], 0, 6));
        $this->assertStringContainsString('{{1}}', $rows['crm.booking_confirmed']['provider_text']);

        $this->putJson('/api/v1/settings/whatsapp/templates', ['templates' => [
            ['key' => 'crm.booking_confirmed', 'provider_name' => 'Booking Confirmed'],
        ]])->assertStatus(422);
        $this->putJson('/api/v1/settings/whatsapp/templates', ['templates' => [
            ['key' => 'crm.booking_confirmed', 'provider_name' => 'booking_confirmed_v1', 'body_params' => ['name', 'number', 'date']],
        ]])->assertOk();
        $this->assertTrue(WhatsAppTemplate::where('key', 'crm.booking_confirmed')->firstOrFail()->isMapped());

        // Off again: share links at once
        $this->putJson('/api/v1/settings/whatsapp', ['provider' => 'share'])->assertOk()->assertJsonPath('data.api_on', false);
        $this->assertSame('whatsapp_share', $this->message()->channel);
        Http::assertNothingSent();
    }

    public function test_mapped_message_goes_by_api_with_its_values(): void
    {
        $this->switchOn();
        $this->map();
        Http::fake(['api.interakt.ai/*' => Http::response(['result' => true, 'message' => 'Message created successfully', 'id' => 'wamid-123'], 201)]);

        $message = $this->message();

        $this->assertSame(CustomerMessage::SENT, $message->fresh()->status);
        $this->assertSame('wamid-123', $message->fresh()->provider_message_id);
        $this->assertSame('interakt', $message->fresh()->channel);

        Http::assertSent(function (Request $request) use ($message) {
            return $request->url() === 'https://api.interakt.ai/v1/public/message/'
                && $request->header('Authorization')[0] === 'Basic ' . self::KEY
                && $request['countryCode'] === '+91'
                && $request['phoneNumber'] === '9876543210'
                && $request['type'] === 'Template'
                && $request['callbackData'] === (string) $message->id
                && $request['template']['name'] === 'booking_confirmed_v1'
                && $request['template']['bodyValues'] === ['Asha Rao', 'BK-7', 'Mon, 20 Oct'];
        });
    }

    public function test_unmapped_or_refused_messages_fall_back_to_the_share_link(): void
    {
        $this->switchOn();
        Http::fake(['api.interakt.ai/*' => Http::response(['result' => false, 'message' => 'Template not found'], 400)]);

        // No template for this kind: nothing sent, the reason is shown
        $message = $this->message()->fresh();
        $this->assertSame(CustomerMessage::PREPARED, $message->status);
        $this->assertStringContainsString('No WhatsApp template', $message->error);
        Http::assertNothingSent();

        // Interakt refuses it: still in "Messages to send" with the reason
        $this->map();
        $message = $this->message()->fresh();
        $this->assertSame(CustomerMessage::PREPARED, $message->status);
        $this->assertStringContainsString('Template not found', $message->error);

        Sanctum::actingAs($this->manager);
        $queued = collect($this->getJson('/api/v1/crm/messages')->assertOk()->json('data.rows'))->firstWhere('id', $message->id);
        $this->assertNotNull($queued);
        $this->assertStringStartsWith('https://wa.me/919876543210', $queued['share_url']);
    }

    public function test_scheduled_messages_go_when_due_and_the_old_queue_is_not_blasted(): void
    {
        $old = $this->message();
        $this->travel(1)->minutes();

        $this->switchOn();
        $this->map();
        Http::fake(['api.interakt.ai/*' => Http::response(['result' => true, 'id' => 'wamid-9'], 201)]);

        $later = $this->message(['scheduled_at' => now()->addHour()]);
        $this->assertSame(CustomerMessage::PREPARED, $later->fresh()->status);
        Http::assertNothingSent();

        $this->travel(61)->minutes();
        $this->artisan('whatsapp:send-due')->assertSuccessful();

        $this->assertSame(CustomerMessage::SENT, $later->fresh()->status);
        $this->assertSame(CustomerMessage::PREPARED, $old->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_webhook_updates_delivery_and_records_stop(): void
    {
        $this->switchOn();
        $this->map();
        Http::fake(['api.interakt.ai/*' => Http::response(['result' => true, 'id' => 'wamid-55'], 201)]);
        $message = $this->message();
        $url = '/api/v1/public/whatsapp/interakt/' . WhatsAppSettings::webhookSecret();

        $this->postJson('/api/v1/public/whatsapp/interakt/wrong-secret', ['type' => 'message_api_delivered'])->assertNotFound();

        $this->postJson($url, ['type' => 'message_api_delivered', 'data' => ['message' => ['id' => 'wamid-55']]])->assertOk();
        $this->postJson($url, ['type' => 'message_api_read', 'data' => ['message' => ['id' => 'wamid-55']]])->assertOk();
        $this->assertNotNull($message->fresh()->delivered_at);
        $this->assertNotNull($message->fresh()->read_at);

        // Failed later: back in the queue for a person to send
        $this->postJson($url, ['type' => 'message_api_failed', 'data' => ['message' => ['callback_data' => (string) $message->id, 'channel_failure_reason' => 'Not a WhatsApp user']]])->assertOk();
        $this->assertSame(CustomerMessage::PREPARED, $message->fresh()->status);
        $this->assertStringContainsString('Not a WhatsApp user', $message->fresh()->error);

        $this->postJson($url, ['type' => 'message_received', 'data' => ['customer' => ['country_code' => '+91', 'phone_number' => '9876543210'], 'message' => ['message' => 'STOP']]])
            ->assertOk()->assertJsonPath('data.handled', 'opted out');
        $this->assertNotNull($this->customer->fresh()->marketing_opt_out_at);
    }
}
