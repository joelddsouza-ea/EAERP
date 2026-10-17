<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Core\Support\WorkshopSettings;
use App\Modules\Hr\Models\AttendanceDay;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Support\Geo;
use App\Modules\Workshop\Models\JobTrip;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Selfie + GPS attendance:
 *  - a punch is taken only at a workshop (within its radius) with a fresh front-camera
 *    selfie; a driver or home-service technician out on a trip punches from the trip;
 *  - in and out alternate; the day is rebuilt from the punches after every change;
 *  - managers fix a missed or wrong punch with a reason (regularisation), never silently.
 */
class AttendanceService
{
    public function __construct(
        protected FileStorageService $files,
        protected LeaveService $leave,
    ) {}

    // ------------------------------------------------------------------ punch

    /**
     * @param array{lat: float|string|null, lng: float|string|null, accuracy?: float|string|null, captured_at?: ?string, client_time?: ?string, device?: ?string} $data
     */
    public function punch(User $user, UploadedFile $selfie, array $data): AttendancePunch
    {
        if (!isset($data['lat'], $data['lng']) || $data['lat'] === '' || $data['lng'] === '') {
            throw new HrException('Turn on location for the app, then try again.', ['location' => ['Location is off.']]);
        }

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $accuracy = isset($data['accuracy']) ? (float) $data['accuracy'] : null;
        $maxAccuracy = (float) WorkshopSettings::get('hr.max_gps_accuracy_m');

        if ($accuracy !== null && $maxAccuracy > 0 && $accuracy > $maxAccuracy) {
            throw new HrException("The phone's location is too rough (±" . round($accuracy) . " m). Step outside or wait a few seconds and try again.", ['location' => ['Location not accurate enough.']]);
        }

        $this->assertFreshSelfie($data);

        $now = now();
        $today = $now->toDateString();
        $last = AttendancePunch::valid()->where('user_id', $user->id)->where('work_date', $today)->latest('punched_at')->first();
        if ($last && $last->punched_at->diffInSeconds($now) < 120) {
            throw new HrException('You have just punched ' . $last->type . '.');
        }
        $type = $last && $last->type === AttendancePunch::IN ? AttendancePunch::OUT : AttendancePunch::IN;

        [$branch, $distance] = $this->nearestBranch($user, $lat, $lng);
        $trip = null;

        if (!$branch || $distance > (int) $branch->geofence_radius_m) {
            $trip = $this->tripToday($user);
            if (!$trip) {
                if ($branch === null) {
                    throw new HrException('The workshop location is not set yet. Ask the manager to set it in the branch profile.');
                }
                throw new HrException("You are " . $this->metres($distance) . " from {$branch->name}. Punch in at the workshop (within {$branch->geofence_radius_m} m).", ['location' => ['Outside the workshop.']]);
            }
        }

        $branchId = $trip ? $trip->branch_id : $branch->id;
        $stored = $this->files->uploadPrivateMedia($selfie, "attendance/{$user->id}/" . $now->format('Y-m'));

        $punch = DB::transaction(function () use ($user, $type, $now, $today, $lat, $lng, $accuracy, $distance, $branchId, $trip, $stored, $data) {
            $punch = AttendancePunch::create([
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'work_date' => $today,
                'type' => $type,
                'punched_at' => $now,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy_m' => $accuracy,
                'distance_m' => $distance !== null ? (int) round($distance) : null,
                'selfie_disk' => $stored['disk'],
                'selfie_path' => $stored['path'],
                'selfie_thumb_path' => $stored['thumbnail_path'],
                'source' => $trip ? AttendancePunch::SOURCE_TRIP : AttendancePunch::SOURCE_SELFIE,
                'job_trip_id' => $trip?->id,
                'device' => isset($data['device']) ? substr((string) $data['device'], 0, 250) : null,
                'created_by' => $user->id,
            ]);
            $this->rebuildDay($user->id, $today);

            return $punch;
        });

        return $punch;
    }

    /**
     * The selfie must be shot just now on the phone's camera (a gallery picture is older).
     * Both times come from the phone, so its clock being off doesn't matter.
     */
    protected function assertFreshSelfie(array $data): void
    {
        $window = (int) WorkshopSettings::get('hr.selfie_window_seconds');
        if ($window <= 0 || empty($data['captured_at']) || empty($data['client_time'])) {
            return;
        }

        $age = Carbon::parse($data['client_time'])->diffInSeconds(Carbon::parse($data['captured_at']), true);
        if ($age > $window) {
            throw new HrException('Take a new selfie with the front camera (not one from the gallery).', ['selfie' => ['The selfie is not fresh.']]);
        }
    }

    /**
     * The closest of the user's workshops that has its location set, and how far it is.
     *
     * @return array{0: ?Branch, 1: ?float}
     */
    public function nearestBranch(User $user, float $lat, float $lng): array
    {
        $best = null;
        $bestDistance = null;

        foreach ($this->branchesFor($user) as $branch) {
            if ($branch->lat === null || $branch->lng === null) {
                continue;
            }
            $d = Geo::distance($lat, $lng, (float) $branch->lat, (float) $branch->lng);
            if ($bestDistance === null || $d < $bestDistance) {
                [$best, $bestDistance] = [$branch, $d];
            }
        }

        return [$best, $bestDistance];
    }

    /**
     * @return Collection<int, Branch>
     */
    public function branchesFor(User $user): Collection
    {
        $branches = $user->isOwner() ? Branch::active()->get() : $user->branches()->where('is_active', true)->get();

        return $branches->isEmpty() ? Branch::active()->get() : $branches;
    }

    /**
     * A pickup, drop or home visit the user is on today (they may punch from the road).
     */
    public function tripToday(User $user): ?JobTrip
    {
        return JobTrip::where('assigned_to', $user->id)
            ->where('status', '!=', JobTrip::CANCELLED)
            ->where(fn ($q) => $q->whereDate('slot_date', today())
                ->orWhereDate('started_at', today())
                ->orWhereDate('completed_at', today()))
            ->orderByRaw("FIELD(status, 'en_route', 'arrived', 'scheduled', 'done', 'unassigned')")
            ->first();
    }

    // ---------------------------------------------------------- regularise

    /**
     * A manager adds a missed punch (or a whole day) for an employee, with a reason.
     */
    public function addManualPunch(User $employee, string $date, string $type, string $time, string $reason, User $by): AttendancePunch
    {
        if (!in_array($type, [AttendancePunch::IN, AttendancePunch::OUT], true)) {
            throw new HrException('Choose in or out.');
        }
        if (!trim($reason)) {
            throw new HrException('Give the reason for the change.', ['reason' => ['Required.']]);
        }
        $at = Carbon::parse("{$date} {$time}");
        if ($at->isFuture()) {
            throw new HrException('A punch cannot be in the future.');
        }
        $this->assertOpen($employee->id, $date);

        return DB::transaction(function () use ($employee, $date, $type, $at, $reason, $by) {
            $punch = AttendancePunch::create([
                'user_id' => $employee->id,
                'branch_id' => $this->homeBranchId($employee),
                'work_date' => $date,
                'type' => $type,
                'punched_at' => $at,
                'source' => AttendancePunch::SOURCE_MANUAL,
                'reason' => $reason,
                'created_by' => $by->id,
            ]);
            $this->rebuildDay($employee->id, $date);

            return $punch;
        });
    }

    public function voidPunch(AttendancePunch $punch, string $reason, User $by): void
    {
        if ($punch->voided_at) {
            throw new HrException('This punch is already removed.');
        }
        if (!trim($reason)) {
            throw new HrException('Give the reason for the change.', ['reason' => ['Required.']]);
        }
        $date = $punch->work_date->toDateString();
        $this->assertOpen($punch->user_id, $date);

        DB::transaction(function () use ($punch, $reason, $by, $date) {
            $punch->update([
                'voided_at' => now(),
                'voided_by' => $by->id,
                'reason' => trim(($punch->reason ? $punch->reason . ' | ' : '') . 'Removed: ' . $reason),
            ]);
            $this->rebuildDay($punch->user_id, $date);
        });
    }

    /**
     * Attendance of a finalised payroll month is locked.
     */
    public function assertOpen(int $userId, string $date): void
    {
        if (app(PayrollService::class)->isLocked($userId, substr($date, 0, 7))) {
            throw new HrException('Payroll for ' . Carbon::parse($date)->format('F Y') . ' is finalised; its attendance can no longer change.');
        }
    }

    // ---------------------------------------------------------------- the day

    /**
     * Rebuild an employee's day from punches, approved leave, weekly off and holidays.
     * Future days, and days before joining or after leaving, have no row.
     */
    public function rebuildDay(int $userId, string $date): ?AttendanceDay
    {
        $profile = EmployeeProfile::with(['shift', 'branch'])->where('user_id', $userId)->first();
        $day = Carbon::parse($date)->startOfDay();

        if ($day->isFuture() || ($profile?->date_joined && $day->lt($profile->date_joined)) || ($profile?->date_left && $day->gt($profile->date_left))) {
            AttendanceDay::where('user_id', $userId)->where('date', $date)->delete();

            return null;
        }

        $punches = AttendancePunch::valid()->where('user_id', $userId)->where('work_date', $date)->orderBy('punched_at')->get();
        $branchId = $punches->first()?->branch_id ?? $profile?->branch_id ?? $this->homeBranchId(User::find($userId));
        $shift = $profile?->effectiveShift();
        $offDay = $this->isWeeklyOff($day, $profile, $branchId);
        $holiday = Holiday::forBranch($branchId)->whereDate('date', $date)->exists();
        $leave = $this->leave->leaveOn($userId, $date);

        // Pairs of in → out
        $worked = 0;
        $openIn = null;
        $firstIn = null;
        $lastOut = null;
        foreach ($punches as $p) {
            if ($p->type === AttendancePunch::IN) {
                $openIn ??= $p->punched_at;
                $firstIn ??= $p->punched_at;
            } elseif ($openIn) {
                $worked += (int) $openIn->diffInMinutes($p->punched_at, true);
                $lastOut = $p->punched_at;
                $openIn = null;
            }
        }
        if ($shift && $worked > (int) $shift->half_day_minutes) {
            $worked = max(0, $worked - (int) $shift->break_minutes);
        }

        $late = 0;
        $ot = 0;
        if ($shift && $firstIn) {
            $start = Carbon::parse("{$date} {$shift->start_time}");
            $lateBy = (int) floor($start->diffInMinutes($firstIn, false));
            $late = !$offDay && !$holiday && $lateBy > (int) $shift->late_grace_minutes ? $lateBy : 0;
        }
        if ($shift && $lastOut) {
            $end = Carbon::parse("{$date} {$shift->end_time}");
            $ot = $this->otMinutes((int) floor($end->diffInMinutes($lastOut, false)));
        }

        $status = match (true) {
            $firstIn !== null && $openIn !== null && $day->lt(today()) => AttendanceDay::INCOMPLETE,
            $firstIn !== null && $lastOut === null => AttendanceDay::PRESENT,
            $firstIn !== null => $shift && $worked < (int) $shift->half_day_minutes ? AttendanceDay::HALF_DAY : AttendanceDay::PRESENT,
            $leave !== null => $leave['portion'] === 'paid' ? AttendanceDay::LEAVE_PAID : AttendanceDay::LEAVE_UNPAID,
            $holiday => AttendanceDay::HOLIDAY,
            $offDay => AttendanceDay::WEEKLY_OFF,
            default => AttendanceDay::ABSENT,
        };

        return AttendanceDay::updateOrCreate(
            ['user_id' => $userId, 'date' => $date],
            [
                'branch_id' => $branchId,
                'first_in' => $firstIn,
                'last_out' => $lastOut,
                'worked_minutes' => $worked,
                'late_minutes' => $late,
                'ot_minutes' => $ot,
                'status' => $status,
                'worked_off_day' => $firstIn !== null && ($offDay || $holiday),
                'leave_request_id' => $leave['request_id'] ?? null,
                'regularised' => $punches->contains('source', AttendancePunch::SOURCE_MANUAL),
            ]
        );
    }

    /**
     * Only the time after the first hour (threshold) past the shift end, rounded down.
     */
    public function otMinutes(int $minutesAfterShiftEnd): int
    {
        $threshold = (int) WorkshopSettings::get('hr.ot_threshold_minutes');
        $rounding = max(1, (int) WorkshopSettings::get('hr.ot_rounding_minutes'));
        $ot = $minutesAfterShiftEnd - $threshold;

        return $ot > 0 ? intdiv($ot, $rounding) * $rounding : 0;
    }

    public function rebuildRange(int $userId, string $from, string $to): void
    {
        $end = min($to, today()->toDateString());
        if ($from > $end) {
            return;
        }
        foreach (CarbonPeriod::create($from, $end) as $d) {
            $this->rebuildDay($userId, $d->toDateString());
        }
    }

    /**
     * Fill the day for every employee who keeps attendance (absent, weekly off, holiday,
     * leave): run nightly for yesterday, and by payroll for its month.
     */
    public function closeDay(string $date): int
    {
        $count = 0;
        foreach ($this->attendanceUserIds($date) as $userId) {
            $this->rebuildDay($userId, $date);
            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int, int>
     */
    public function attendanceUserIds(string $date, ?int $branchId = null): Collection
    {
        return EmployeeProfile::query()
            ->where('attendance_required', true)
            ->where(fn ($q) => $q->whereNull('date_joined')->orWhere('date_joined', '<=', $date))
            ->where(fn ($q) => $q->whereNull('date_left')->orWhere('date_left', '>=', substr($date, 0, 7) . '-01'))
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('user_id');
    }

    public function isWeeklyOff(Carbon $day, ?EmployeeProfile $profile, ?int $branchId): bool
    {
        $off = $profile ? $profile->weeklyOff() : (int) (Branch::whereKey($branchId)->value('weekly_off_day') ?? 0);

        return $day->dayOfWeek === $off;
    }

    public function homeBranchId(?User $user): ?int
    {
        if (!$user) {
            return null;
        }

        return EmployeeProfile::where('user_id', $user->id)->value('branch_id')
            ?? $user->primaryBranch()?->id
            ?? $user->branches()->value('branches.id');
    }

    // ------------------------------------------------------------ presenting

    /**
     * What the punch screen shows: today's punches, the next punch, the workshops.
     */
    public function today(User $user): array
    {
        $today = today()->toDateString();
        $punches = AttendancePunch::valid()->with(['branch:id,name', 'createdBy:id,name'])->where('user_id', $user->id)->where('work_date', $today)->orderBy('punched_at')->get();
        $day = AttendanceDay::where('user_id', $user->id)->where('date', $today)->first();
        $profile = EmployeeProfile::with('shift')->where('user_id', $user->id)->first();
        $shift = $profile?->effectiveShift() ?? \App\Modules\Hr\Models\Shift::defaultShift();
        $trip = $this->tripToday($user);

        return [
            'date' => $today,
            'next' => $punches->last()?->type === AttendancePunch::IN ? AttendancePunch::OUT : AttendancePunch::IN,
            'punches' => $punches->map(fn (AttendancePunch $p) => $this->presentPunch($p))->values(),
            'worked_minutes' => $day?->worked_minutes ?? 0,
            'status' => $day?->status,
            'shift' => $shift ? ['name' => $shift->name, 'start' => substr($shift->start_time, 0, 5), 'end' => substr($shift->end_time, 0, 5)] : null,
            'branches' => $this->branchesFor($user)->map(fn (Branch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'lat' => $b->lat !== null ? (float) $b->lat : null,
                'lng' => $b->lng !== null ? (float) $b->lng : null,
                'radius_m' => (int) $b->geofence_radius_m,
            ])->values(),
            'trip' => $trip ? ['id' => $trip->id, 'label' => $trip->label(), 'status' => $trip->status] : null,
            'max_accuracy_m' => (int) WorkshopSettings::get('hr.max_gps_accuracy_m'),
            'selfie_window_seconds' => (int) WorkshopSettings::get('hr.selfie_window_seconds'),
        ];
    }

    public function presentPunch(AttendancePunch $p, bool $withSelfie = true): array
    {
        return [
            'id' => $p->id,
            'type' => $p->type,
            'at' => $p->punched_at->toIso8601String(),
            'time' => $p->punched_at->format('H:i'),
            'source' => $p->source,
            'distance_m' => $p->distance_m,
            'accuracy_m' => $p->accuracy_m,
            'lat' => $p->lat,
            'lng' => $p->lng,
            'reason' => $p->reason,
            'branch' => $p->branch?->name,
            'by' => $p->source === AttendancePunch::SOURCE_MANUAL ? $p->createdBy?->name : null,
            'selfie_url' => $withSelfie ? $p->selfieUrl() : null,
            'selfie_thumb_url' => $withSelfie ? $p->selfieUrl(true) : null,
            'voided' => $p->voided_at !== null,
        ];
    }

    public function presentDay(?AttendanceDay $d): ?array
    {
        if (!$d) {
            return null;
        }

        return [
            'date' => $d->date->toDateString(),
            'status' => $d->status,
            'label' => AttendanceDay::LABELS[$d->status] ?? $d->status,
            'first_in' => $d->first_in?->format('H:i'),
            'last_out' => $d->last_out?->format('H:i'),
            'worked_minutes' => $d->worked_minutes,
            'late_minutes' => $d->late_minutes,
            'ot_minutes' => $d->ot_minutes,
            'worked_off_day' => $d->worked_off_day,
            'regularised' => $d->regularised,
        ];
    }

    protected function metres(?float $m): string
    {
        return $m === null ? '?' : ($m >= 1000 ? number_format($m / 1000, 1) . ' km' : round($m) . ' m');
    }
}
