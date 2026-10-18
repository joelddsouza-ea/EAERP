<?php

namespace Tests\Feature;

use App\Core\Models\User;
use App\Core\Support\PublicUrl;
use App\Modules\Crm\Models\CrmLink;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 9: the public address master (links sent out use it) and the staff app's ping.
 */
class AppAddressSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
    }

    public function test_ping_answers_without_login(): void
    {
        $this->getJson('/api/v1/app/ping')->assertOk()->assertJsonPath('data.app', 'eaerp');
    }

    public function test_owner_sets_the_public_address_and_links_use_it(): void
    {
        $manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/settings/app')->assertForbidden();

        Sanctum::actingAs(User::where('email', 'admin@expressautocare.in')->firstOrFail());
        $this->putJson('/api/v1/settings/app', ['settings' => ['app.public_url' => 'not a url']])->assertStatus(422);
        $this->putJson('/api/v1/settings/app', ['settings' => ['customer_app.upi_id' => 'bad upi']])->assertStatus(422);

        $this->putJson('/api/v1/settings/app', ['settings' => [
            'app.public_url' => 'https://erp.expressautocare.in/',
            'customer_app.upi_id' => 'expressautocare@okicici',
        ]])->assertOk()
            ->assertJsonPath('data.effective_url', 'https://erp.expressautocare.in')
            ->assertJsonPath('data.customer_app_url', 'https://erp.expressautocare.in/app');

        $this->assertSame('https://erp.expressautocare.in/j/abc', PublicUrl::to('/j/abc'));
        $link = CrmLink::issue(['purpose' => CrmLink::BOOK, 'expires_at' => now()->addDays(5)]);
        $this->assertStringStartsWith('https://erp.expressautocare.in/c/', $link->url());

        // Cleared: back to this server's own address
        $this->putJson('/api/v1/settings/app', ['settings' => ['app.public_url' => '']])->assertOk();
        $this->assertNull(PublicUrl::base());
    }
}
