<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\StaffLiveStatus;
use App\Modules\Hr\Models\StaffLocation;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8: live location from punch in to punch out, the owner / manager map and trails.
 */
class LiveLocationTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 19.2183300;
    private const LNG = 72.9781000;

    private User $owner;
    private User $manager;
    private User $tech;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        Storage::fake('job_media');

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->branch = $this->tech->branches()->firstOrFail();
        $this->branch->update(['lat' => self::LAT, 'lng' => self::LNG, 'geofence_radius_m' => 150]);
        EmployeeProfile::create(['user_id' => $this->tech->id, 'branch_id' => $this->branch->id, 'monthly_salary' => 20000, 'date_joined' => '2025-01-01']);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branch->id);
    }

    private function punch(): void
    {
        $now = now();
        $this->as($this->tech)->post('/api/v1/hr/me/punch', [
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
            'lat' => self::LAT + 0.0002,
            'lng' => self::LNG,
            'accuracy' => 12,
            'captured_at' => $now->copy()->subSeconds(5)->toIso8601String(),
            'client_time' => $now->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function send(array $points, array $extra = [])
    {
        return $this->as($this->tech)->postJson('/api/v1/hr/me/locations', ['source' => 'web', 'points' => $points, ...$extra]);
    }

    private function point(float $dLat, string $time, array $over = []): array
    {
        return ['lat' => self::LAT + $dLat, 'lng' => self::LNG, 'accuracy' => 15, 'speed' => 0, 'recorded_at' => today()->setTimeFromTimeString($time)->toIso8601String(), 'battery' => 80, ...$over];
    }

    public function test_tracking_runs_only_between_punch_in_and_punch_out(): void
    {
        $this->travelTo(today()->setTime(9, 0));

        // Not punched in: nothing is kept, the app is told to stop
        $this->send([$this->point(0, '08:59')])->assertOk()->assertJsonPath('data.tracking', false)->assertJsonPath('data.accepted', 0);
        $this->as($this->tech)->getJson('/api/v1/hr/me/today')->assertJsonPath('data.tracking.required', false);

        $this->punch();
        $this->as($this->tech)->getJson('/api/v1/hr/me/today')
            ->assertJsonPath('data.tracking.required', true)
            ->assertJsonPath('data.tracking.consent', false);
        $this->as($this->tech)->postJson('/api/v1/hr/me/location-consent')->assertOk()->assertJsonPath('data.consent', true);

        $this->travelTo(today()->setTime(10, 0));
        $res = $this->send([
            $this->point(0.01, '09:30'),
            $this->point(0.01, '09:30'),                       // resent: kept once
            $this->point(0.02, '09:45', ['accuracy' => 900]),  // too rough
            $this->point(0.03, '08:00'),                       // before punch in
            $this->point(0.02, '09:50', ['speed' => 8]),
        ])->assertOk();
        $res->assertJsonPath('data.tracking', true)->assertJsonPath('data.accepted', 2);

        $this->assertSame(2, StaffLocation::where('user_id', $this->tech->id)->count());
        $status = StaffLiveStatus::where('user_id', $this->tech->id)->firstOrFail();
        $this->assertSame(StaffLiveStatus::ACTIVE, $status->state);
        $this->assertEqualsWithDelta(self::LAT + 0.02, $status->lat, 0.0000001);

        // Resending the same batch after a timeout adds nothing
        $this->send([$this->point(0.01, '09:30')])->assertJsonPath('data.accepted', 0);

        // Punch out: sharing stops and later points are refused
        $this->travelTo(today()->setTime(19, 30));
        $this->punch();
        $this->assertSame(StaffLiveStatus::STOPPED, $status->fresh()->state);
        $this->send([$this->point(0.01, '19:31')])->assertJsonPath('data.tracking', false);
        $this->assertSame(2, StaffLocation::count());
    }

    public function test_switched_off_for_the_employee_or_in_settings_nothing_is_kept(): void
    {
        $this->travelTo(today()->setTime(9, 0));
        $this->punch();

        EmployeeProfile::where('user_id', $this->tech->id)->update(['track_location' => false]);
        $this->send([$this->point(0, '09:01')])->assertJsonPath('data.tracking', false);

        EmployeeProfile::where('user_id', $this->tech->id)->update(['track_location' => true]);
        WorkshopSettings::set('hr.tracking.enabled', false);
        $this->send([$this->point(0, '09:01')])->assertJsonPath('data.tracking', false);

        WorkshopSettings::set('hr.tracking.enabled', true);
        WorkshopSettings::set('modules.hr', false);
        $this->send([$this->point(0, '09:01')])->assertNotFound();

        $this->assertSame(0, StaffLocation::count());
    }

    public function test_owner_and_manager_see_the_live_map_and_a_trail_others_do_not(): void
    {
        $this->travelTo(today()->setTime(9, 0));
        $this->punch();
        $this->travelTo(today()->setTime(11, 0));

        // At the workshop 9:05–9:40 (a stop), then driving about 2.2 km north
        $this->send([
            $this->point(0.0001, '09:05'),
            $this->point(0.0002, '09:20'),
            $this->point(0.0001, '09:40'),
            $this->point(0.01, '09:50', ['speed' => 9]),
            $this->point(0.02, '10:00', ['speed' => 9]),
            $this->point(0.02, '10:58'),
        ])->assertJsonPath('data.accepted', 6);

        $live = $this->as($this->manager)->getJson('/api/v1/hr/tracking/live')->assertOk();
        $person = collect($live->json('data.people'))->firstWhere('user_id', $this->tech->id);
        $this->assertSame('still', $person['state']);
        $this->assertSame(2, $person['minutes_ago']);
        $this->assertNull($person['at_workshop']);
        $this->assertNotEmpty($live->json('data.branches'));

        $trail = $this->as($this->owner)->getJson("/api/v1/hr/tracking/users/{$this->tech->id}/trail?date=" . today()->toDateString())->assertOk();
        $this->assertCount(6, $trail->json('data.points'));
        $this->assertEqualsWithDelta(2.2, $trail->json('data.km'), 0.2);
        $this->assertSame('09:05', $trail->json('data.stops.0.from'));
        $this->assertSame(35, $trail->json('data.stops.0.minutes'));
        $this->assertContains(58, array_column($trail->json('data.gaps'), 'minutes'));

        // Twenty minutes later with nothing new: "No signal"
        $this->travelTo(today()->setTime(11, 20));
        $person = collect($this->as($this->owner)->getJson('/api/v1/hr/tracking/live')->json('data.people'))->firstWhere('user_id', $this->tech->id);
        $this->assertSame('no_signal', $person['state']);

        $this->as($this->tech)->getJson('/api/v1/hr/tracking/live')->assertForbidden();
        $this->as($this->tech)->getJson("/api/v1/hr/tracking/users/{$this->owner->id}/trail")->assertForbidden();
    }

    public function test_housekeeping_stops_forgotten_sharing_and_prunes_old_points(): void
    {
        $this->travelTo(today()->setTime(9, 0));
        $this->punch();
        $this->send([$this->point(0, '09:01')]);
        StaffLocation::create([
            'user_id' => $this->tech->id, 'work_date' => today()->subDays(200)->toDateString(), 'recorded_at' => now()->subDays(200),
            'received_at' => now()->subDays(200), 'lat' => self::LAT, 'lng' => self::LNG, 'source' => 'web',
        ]);

        // Next day, never punched out
        $this->travelTo(today()->addDay()->setTime(0, 40));
        $this->artisan('hr:tracking --prune')->assertSuccessful();

        $this->assertSame(StaffLiveStatus::STOPPED, StaffLiveStatus::where('user_id', $this->tech->id)->value('state'));
        $this->assertSame(1, StaffLocation::count());
    }

    public function test_manager_switches_tracking_per_employee(): void
    {
        $this->as($this->owner)->putJson("/api/v1/hr/employees/{$this->tech->id}/profile", ['track_location' => false])->assertOk();
        $this->assertFalse((bool) EmployeeProfile::where('user_id', $this->tech->id)->value('track_location'));
    }
}
