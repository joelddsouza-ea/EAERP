<?php

namespace Tests\Feature;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Hr\Models\AttendanceDay;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\Vehicle;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6: selfie + GPS attendance at the workshop.
 */
class AttendancePunchTest extends TestCase
{
    use RefreshDatabase;

    // The workshop, and a spot about 25 m and about 2 km away
    private const LAT = 19.2183300;
    private const LNG = 72.9781000;

    private User $owner;
    private User $manager;
    private User $tech;
    private User $driver;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([OrganizationSeeder::class, RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        Storage::fake('job_media');

        $this->owner = User::where('email', 'admin@expressautocare.in')->firstOrFail();
        $this->manager = User::where('email', 'manager@expressautocare.in')->firstOrFail();
        $this->tech = User::where('email', 'tech1@expressautocare.in')->firstOrFail();
        $this->driver = User::where('email', 'driver@expressautocare.in')->firstOrFail();
        $this->branch = $this->tech->branches()->firstOrFail();
        $this->branch->update(['lat' => self::LAT, 'lng' => self::LNG, 'geofence_radius_m' => 150]);

        foreach ([$this->tech, $this->driver] as $user) {
            EmployeeProfile::create(['user_id' => $user->id, 'branch_id' => $this->branch->id, 'monthly_salary' => 20000, 'date_joined' => '2025-01-01']);
        }
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this->withHeader('X-Branch-Id', (string) $this->branch->id);
    }

    private function punch(User $user, array $over = [])
    {
        $now = now();

        return $this->as($user)->post('/api/v1/hr/me/punch', array_merge([
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
            'lat' => self::LAT + 0.0002,
            'lng' => self::LNG,
            'accuracy' => 12,
            'captured_at' => $now->copy()->subSeconds(5)->toIso8601String(),
            'client_time' => $now->toIso8601String(),
        ], $over), ['Accept' => 'application/json']);
    }

    public function test_punch_in_and_out_at_the_workshop(): void
    {
        $this->travelTo(today()->setTime(9, 20));
        $this->punch($this->tech)->assertCreated()->assertJsonPath('data.next', 'out');

        $punch = AttendancePunch::where('user_id', $this->tech->id)->firstOrFail();
        $this->assertSame('in', $punch->type);
        $this->assertSame('selfie', $punch->source);
        $this->assertLessThan(40, $punch->distance_m);
        Storage::disk('job_media')->assertExists($punch->selfie_path);

        // Double tap is ignored
        $this->punch($this->tech)->assertStatus(422);

        $this->travelTo(today()->setTime(21, 10));
        $this->punch($this->tech)->assertCreated()->assertJsonPath('data.next', 'in');

        $day = AttendanceDay::where('user_id', $this->tech->id)->firstOrFail();
        $this->assertSame(AttendanceDay::PRESENT, $day->status);
        // Shift ends 19:00; the first hour after it is not OT: 21:10 → 1 h 10 m, rounded down to 15 min
        $this->assertSame(60, $day->ot_minutes);
        $this->assertSame(0, $day->late_minutes);
    }

    public function test_outside_the_radius_location_off_rough_fix_or_old_selfie_is_refused(): void
    {
        $this->punch($this->tech, ['lat' => self::LAT + 0.02])
            ->assertStatus(422)
            ->assertJsonFragment(['location' => ['Outside the workshop.']]);

        $this->punch($this->tech, ['lat' => '', 'lng' => ''])->assertStatus(422)->assertJsonFragment(['location' => ['Location is off.']]);
        $this->punch($this->tech, ['accuracy' => 400])->assertStatus(422);
        $this->punch($this->tech, ['captured_at' => now()->subMinutes(10)->toIso8601String()])
            ->assertStatus(422)->assertJsonFragment(['selfie' => ['The selfie is not fresh.']]);
        $this->as($this->tech)->post('/api/v1/hr/me/punch', ['lat' => self::LAT, 'lng' => self::LNG], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_workshop_without_a_location_cannot_take_punches(): void
    {
        $this->branch->update(['lat' => null, 'lng' => null]);

        $this->punch($this->tech)->assertStatus(422)->assertJsonFragment(['message' => 'The workshop location is not set yet. Ask the manager to set it in the branch profile.']);
    }

    public function test_driver_on_a_trip_punches_from_the_road(): void
    {
        $customer = Customer::create(['name' => 'Asha', 'phone' => '9000000001']);
        $vehicle = Vehicle::create(['customer_id' => $customer->id, 'registration_no' => 'MH04AA1111']);
        $jobId = DB::table('job_cards')->insertGetId([
            'branch_id' => $this->branch->id, 'job_number' => 'JC-T-1', 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'status' => 'pickup_scheduled', 'service_type' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobTrip::create([
            'branch_id' => $this->branch->id, 'job_card_id' => $jobId, 'kind' => 'pickup', 'status' => 'scheduled',
            'assigned_to' => $this->driver->id, 'slot_date' => today()->toDateString(),
        ]);

        // 2 km away: the technician is refused, the driver on a trip is not
        $far = ['lat' => self::LAT + 0.018];
        $this->punch($this->tech, $far)->assertStatus(422);
        $this->punch($this->driver, $far)->assertCreated();

        $punch = AttendancePunch::where('user_id', $this->driver->id)->firstOrFail();
        $this->assertSame('trip', $punch->source);
        $this->assertNotNull($punch->job_trip_id);
        $this->assertGreaterThan(1500, $punch->distance_m);
    }

    public function test_manager_regularises_a_missed_punch_with_a_reason(): void
    {
        $yesterday = today()->subDay()->toDateString();
        $url = "/api/v1/hr/attendance/users/{$this->tech->id}/punches";

        // Technicians cannot regularise
        $this->as($this->tech)->postJson($url, ['date' => $yesterday, 'type' => 'in', 'time' => '09:30', 'reason' => 'x'])->assertForbidden();

        $this->as($this->manager)->postJson($url, ['date' => $yesterday, 'type' => 'in', 'time' => '09:30'])->assertStatus(422);
        $this->as($this->manager)->postJson($url, ['date' => $yesterday, 'type' => 'in', 'time' => '09:30', 'reason' => 'Phone battery dead'])->assertOk();

        // Only in: the out-punch is missing
        $this->assertSame(AttendanceDay::INCOMPLETE, AttendanceDay::where('user_id', $this->tech->id)->where('date', $yesterday)->value('status'));

        $this->as($this->manager)->postJson($url, ['date' => $yesterday, 'type' => 'out', 'time' => '19:05', 'reason' => 'Phone battery dead'])
            ->assertOk()
            ->assertJsonPath('data.day.status', 'present')
            ->assertJsonPath('data.day.regularised', true);

        $punch = AttendancePunch::where('user_id', $this->tech->id)->where('type', 'out')->firstOrFail();
        $this->assertSame('manual', $punch->source);
        $this->assertSame($this->manager->id, $punch->created_by);

        // Removing a punch keeps it on record
        $this->as($this->manager)->postJson("/api/v1/hr/attendance/punches/{$punch->id}/void", ['reason' => 'Entered by mistake'])->assertOk();
        $this->assertNotNull($punch->fresh()->voided_at);
        $this->assertSame(AttendanceDay::INCOMPLETE, AttendanceDay::where('user_id', $this->tech->id)->where('date', $yesterday)->value('status'));
    }

    public function test_staff_without_email_are_created_and_log_in_with_their_mobile(): void
    {
        Sanctum::actingAs($this->owner);
        $this->withHeader('X-Branch-Id', (string) $this->branch->id)->postJson('/api/v1/user-admin/users', [
            'name' => 'Aamir Khan',
            'phone' => '91234 56780',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'roles' => ['employee'],
            'branches' => [$this->branch->id],
            'primary_branch_id' => $this->branch->id,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => '+91 9123456780', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Aamir Khan')
            ->assertJsonFragment(['hr.self']);
    }

    public function test_register_shows_everyone_and_marks_absentees(): void
    {
        $this->travelTo(today()->setTime(10, 0));
        $this->punch($this->tech)->assertCreated();

        $this->as($this->manager)->getJson('/api/v1/hr/attendance/day')
            ->assertOk()
            ->assertJsonPath('data.counts.employees', 2)
            ->assertJsonPath('data.counts.in_now', 1);

        // A past day is closed: the driver, who never punched, is absent
        $yesterday = today()->subDay();
        $this->travelTo($yesterday->copy()->addDays(2)->setTime(8, 0));
        $this->artisan('hr:close-attendance', ['date' => $yesterday->toDateString()])->assertSuccessful();
        $status = AttendanceDay::where('user_id', $this->driver->id)->where('date', $yesterday->toDateString())->value('status');
        $this->assertContains($status, [AttendanceDay::ABSENT, AttendanceDay::WEEKLY_OFF]);
    }
}
