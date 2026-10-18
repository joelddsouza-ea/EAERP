<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\Modules;
use App\Core\Support\WorkshopSettings;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\StaffLiveStatus;
use App\Modules\Hr\Models\StaffLocation;
use App\Modules\Hr\Support\Geo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Live staff location: from punch in to punch out the phone sends its position (the web
 * app while it is open, the Android app also in the background). Points are taken only
 * while the employee is punched in today and tracking is on for them; the owner and
 * managers see everyone on a map and each person's trail for a day.
 */
class LocationService
{
    public const MAX_BATCH = 500;

    public function __construct(protected AttendanceService $attendance) {}

    /**
     * Whether the employee's phone should be sending its position now.
     */
    public function required(User $user): bool
    {
        return $this->enabledFor($user) && $this->punchedIn($user->id);
    }

    public function enabledFor(User $user): bool
    {
        if (!Modules::hr() || !WorkshopSettings::get('hr.tracking.enabled')) {
            return false;
        }

        $profile = EmployeeProfile::where('user_id', $user->id)->first(['id', 'track_location']);

        return $profile === null || $profile->track_location;
    }

    public function punchedIn(int $userId): bool
    {
        return AttendancePunch::valid()->where('user_id', $userId)->where('work_date', today()->toDateString())
            ->latest('punched_at')->value('type') === AttendancePunch::IN;
    }

    /**
     * What the app needs to run the tracker.
     */
    public function clientConfig(User $user): array
    {
        return [
            'required' => $this->required($user),
            'enabled' => $this->enabledFor($user),
            'interval_seconds' => (int) WorkshopSettings::get('hr.tracking.interval_seconds'),
            'distance_filter_m' => (int) WorkshopSettings::get('hr.tracking.distance_filter_m'),
            'consent' => EmployeeProfile::where('user_id', $user->id)->value('location_consent_at') !== null,
            'retention_days' => (int) WorkshopSettings::get('hr.tracking.retention_days'),
        ];
    }

    public function consent(User $user): void
    {
        $profile = EmployeeProfile::firstOrCreate(['user_id' => $user->id], ['branch_id' => $user->branches()->value('branches.id')]);
        if (!$profile->location_consent_at) {
            $profile->update(['location_consent_at' => now()]);
        }
    }

    /**
     * Store a batch of points from the phone. Points outside a punched-in period, too rough,
     * from the future or already stored are skipped (the phone may resend after a timeout).
     *
     * @param array<int, array{lat: float, lng: float, accuracy?: ?float, speed?: ?float, heading?: ?float, recorded_at: string, battery?: ?float, charging?: ?bool}> $points
     * @return array{accepted: int, skipped: int, tracking: bool}
     */
    public function ingest(User $user, array $points, string $source, ?string $gap = null): array
    {
        if (!$this->required($user)) {
            return ['accepted' => 0, 'skipped' => count($points), 'tracking' => false];
        }

        $maxAccuracy = (float) WorkshopSettings::get('hr.tracking.max_accuracy_m');
        $today = today()->toDateString();
        $since = AttendancePunch::valid()->where('user_id', $user->id)->where('work_date', $today)
            ->where('type', AttendancePunch::IN)->latest('punched_at')->value('punched_at');
        $since = $since ? Carbon::parse($since)->subMinutes(2) : today();
        $trip = $this->attendance->tripToday($user);
        $branchId = $this->homeBranchId($user);

        $rows = [];
        foreach (array_slice($points, 0, self::MAX_BATCH) as $p) {
            $at = Carbon::parse($p['recorded_at'])->setTimezone(config('app.timezone'));
            $accuracy = isset($p['accuracy']) ? (float) $p['accuracy'] : null;
            if ($at->lt($since) || $at->gt(now()->addMinutes(2)) || ($maxAccuracy > 0 && $accuracy !== null && $accuracy > $maxAccuracy)) {
                continue;
            }
            $key = $at->format('Y-m-d H:i:s');
            $rows[$key] = [
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'work_date' => $today,
                'recorded_at' => $key,
                'received_at' => now()->format('Y-m-d H:i:s'),
                'lat' => round((float) $p['lat'], 7),
                'lng' => round((float) $p['lng'], 7),
                'accuracy_m' => $accuracy !== null ? round($accuracy, 1) : null,
                'speed_mps' => isset($p['speed']) && $p['speed'] !== null && $p['speed'] >= 0 ? round(min((float) $p['speed'], 9999), 2) : null,
                'heading' => isset($p['heading']) && $p['heading'] !== null ? ((int) round((float) $p['heading'])) % 360 : null,
                'battery_pct' => isset($p['battery']) && $p['battery'] !== null ? max(0, min(100, (int) round((float) $p['battery']))) : null,
                'is_charging' => isset($p['charging']) ? (bool) $p['charging'] : null,
                'source' => $source,
                'job_trip_id' => $trip?->id,
            ];
        }

        $accepted = $rows ? StaffLocation::insertOrIgnore(array_values($rows)) : 0;
        $skipped = count($points) - $accepted;

        $status = StaffLiveStatus::firstOrNew(['user_id' => $user->id]);
        $latest = $rows ? collect($rows)->sortKeys()->last() : null;
        if ($latest && (!$status->recorded_at || Carbon::parse($latest['recorded_at'])->gte($status->recorded_at))) {
            $status->fill([
                'lat' => $latest['lat'],
                'lng' => $latest['lng'],
                'accuracy_m' => $latest['accuracy_m'],
                'speed_mps' => $latest['speed_mps'],
                'recorded_at' => $latest['recorded_at'],
                'battery_pct' => $latest['battery_pct'] ?? $status->battery_pct,
                'gap_reason' => null,
                'gap_at' => null,
            ]);
        }
        if ($gap && in_array($gap, StaffLiveStatus::GAPS, true)) {
            $status->gap_reason = $gap;
            $status->gap_at = now();
        }
        if ($status->state !== StaffLiveStatus::ACTIVE) {
            $status->started_at = now();
        }
        $status->fill([
            'branch_id' => $branchId,
            'source' => $source,
            'state' => StaffLiveStatus::ACTIVE,
            'stopped_at' => null,
            'job_trip_id' => $trip?->id,
        ])->save();

        return ['accepted' => $accepted, 'skipped' => $skipped, 'tracking' => true];
    }

    /**
     * Punched out (or the day closed): the phone stops sending and the map shows the last place.
     */
    public function stop(int $userId): void
    {
        StaffLiveStatus::where('user_id', $userId)->where('state', StaffLiveStatus::ACTIVE)
            ->update(['state' => StaffLiveStatus::STOPPED, 'stopped_at' => now(), 'gap_reason' => null, 'updated_at' => now()]);
    }

    /**
     * Stop everyone who is no longer punched in (forgotten punch-out, voided punch, new day).
     */
    public function stopStale(): int
    {
        $count = 0;
        foreach (StaffLiveStatus::where('state', StaffLiveStatus::ACTIVE)->pluck('user_id') as $userId) {
            if (!$this->punchedIn($userId)) {
                $this->stop($userId);
                $count++;
            }
        }

        return $count;
    }

    public function prune(): int
    {
        $days = (int) WorkshopSettings::get('hr.tracking.retention_days');
        if ($days <= 0) {
            return 0;
        }

        return StaffLocation::where('work_date', '<', today()->subDays($days)->toDateString())->delete();
    }

    // ------------------------------------------------------------------ owner / manager views

    /**
     * Everyone who punched in today in the viewer's branches, with their latest position.
     */
    public function live(User $viewer, ?int $branchId = null): array
    {
        $today = today()->toDateString();
        $branchIds = $branchId ? [$branchId] : ($viewer->isOwner() ? null : $viewer->getAccessibleBranchIds());

        $punches = AttendancePunch::valid()->where('work_date', $today)
            ->when($branchIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('branch_id', $branchIds)
                ->orWhereIn('user_id', EmployeeProfile::whereIn('branch_id', $branchIds)->select('user_id'))))
            ->orderBy('punched_at')
            ->get(['user_id', 'type', 'punched_at', 'branch_id']);

        $byUser = $punches->groupBy('user_id');
        $users = User::with(['roles:id,name,slug', 'branches:id,name'])->whereIn('id', $byUser->keys())->get()->keyBy('id');
        $statuses = StaffLiveStatus::with('trip.jobCard:id,job_number')->whereIn('user_id', $byUser->keys())->get()->keyBy('user_id');
        $profiles = EmployeeProfile::whereIn('user_id', $byUser->keys())->get(['user_id', 'track_location', 'designation'])->keyBy('user_id');
        $branches = Branch::active()->get(['id', 'name', 'lat', 'lng', 'geofence_radius_m']);
        $stale = (int) WorkshopSettings::get('hr.tracking.stale_minutes');

        $people = [];
        foreach ($byUser as $userId => $list) {
            $user = $users[$userId] ?? null;
            if (!$user) {
                continue;
            }
            $status = $statuses[$userId] ?? null;
            $in = $list->last()->type === AttendancePunch::IN;
            $minutes = $status?->recorded_at ? (int) floor($status->recorded_at->diffInSeconds(now(), true) / 60) : null;
            $tracked = $profiles[$userId]->track_location ?? true;

            $state = match (true) {
                !$in => 'punched_out',
                !$tracked => 'not_tracked',
                $status?->recorded_at === null || $status->recorded_at->lt($list->where('type', AttendancePunch::IN)->last()->punched_at->copy()->subMinutes(2)) => 'waiting',
                $minutes > $stale => 'no_signal',
                ($status->speed_mps ?? 0) >= 1.5 => 'moving',
                default => 'still',
            };

            $place = null;
            if ($status?->lat !== null) {
                foreach ($branches as $b) {
                    if ($b->lat !== null && Geo::distance($status->lat, $status->lng, (float) $b->lat, (float) $b->lng) <= max(50, (int) $b->geofence_radius_m)) {
                        $place = $b->name;
                        break;
                    }
                }
            }

            $people[] = [
                'user_id' => $userId,
                'name' => $user->name,
                'role' => $user->roles->first()?->name,
                'designation' => $profiles[$userId]->designation ?? null,
                'branch' => $user->branches->first()?->name,
                'state' => $state,
                'gap_reason' => $state === 'punched_out' ? null : $status?->gap_reason,
                'punched_in_at' => $list->where('type', AttendancePunch::IN)->first()?->punched_at->format('H:i'),
                'punched_out_at' => $in ? null : $list->last()->punched_at->format('H:i'),
                'lat' => $status?->lat,
                'lng' => $status?->lng,
                'accuracy_m' => $status?->accuracy_m,
                'last_seen_at' => $status?->recorded_at?->toIso8601String(),
                'minutes_ago' => $minutes,
                'battery_pct' => $status?->battery_pct,
                'source' => $status?->source,
                'at_workshop' => $place,
                'trip' => $status?->trip && $in ? ['id' => $status->trip->id, 'label' => $status->trip->label(), 'status' => $status->trip->status, 'job_number' => $status->trip->jobCard?->job_number, 'job_card_id' => $status->trip->job_card_id] : null,
            ];
        }

        $order = ['no_signal' => 0, 'moving' => 1, 'still' => 2, 'waiting' => 3, 'not_tracked' => 4, 'punched_out' => 5];
        usort($people, fn ($a, $b) => [$order[$a['state']], $a['name']] <=> [$order[$b['state']], $b['name']]);

        return [
            'people' => $people,
            'branches' => $branches->filter(fn ($b) => $b->lat !== null && ($branchIds === null || in_array($b->id, $branchIds)))->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'lat' => (float) $b->lat, 'lng' => (float) $b->lng, 'radius_m' => (int) $b->geofence_radius_m,
            ])->values(),
            'stale_minutes' => $stale,
            'tracking_enabled' => (bool) WorkshopSettings::get('hr.tracking.enabled'),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The employee's day: points in order, distance travelled and the places they stayed.
     */
    public function trail(User $employee, string $date): array
    {
        $points = StaffLocation::where('user_id', $employee->id)->where('work_date', $date)->orderBy('recorded_at')
            ->get(['recorded_at', 'lat', 'lng', 'accuracy_m', 'speed_mps', 'battery_pct', 'source', 'job_trip_id']);

        $punches = AttendancePunch::valid()->with('branch:id,name')->where('user_id', $employee->id)->where('work_date', $date)
            ->orderBy('punched_at')->get();

        return [
            'user' => ['id' => $employee->id, 'name' => $employee->name],
            'date' => $date,
            'points' => $points->map(fn (StaffLocation $p) => [
                't' => $p->recorded_at->toIso8601String(),
                'lat' => $p->lat,
                'lng' => $p->lng,
                'acc' => $p->accuracy_m,
                'spd' => $p->speed_mps,
                'bat' => $p->battery_pct,
                'trip' => $p->job_trip_id,
            ])->values(),
            'punches' => $punches->map(fn (AttendancePunch $p) => [
                'type' => $p->type, 'time' => $p->punched_at->format('H:i'), 'lat' => $p->lat, 'lng' => $p->lng, 'branch' => $p->branch?->name, 'source' => $p->source,
            ])->values(),
            'km' => round($this->distanceKm($points), 1),
            'stops' => $this->stops($points),
            'gaps' => $this->gaps($points),
        ];
    }

    /**
     * Distance along the trail, ignoring GPS jitter: a step counts only once the phone is
     * clearly away from the last counted spot.
     *
     * @param Collection<int, StaffLocation> $points
     */
    public function distanceKm(Collection $points): float
    {
        $metres = 0.0;
        $anchor = null;
        foreach ($points as $p) {
            if (!$anchor) {
                $anchor = $p;
                continue;
            }
            $d = Geo::distance($anchor->lat, $anchor->lng, $p->lat, $p->lng);
            if ($d >= max(25.0, (float) ($p->accuracy_m ?? 0), (float) ($anchor->accuracy_m ?? 0))) {
                $metres += $d;
                $anchor = $p;
            }
        }

        return $metres / 1000;
    }

    /**
     * Places the employee stayed (within 100 m) for at least the stop time in the settings.
     *
     * @param Collection<int, StaffLocation> $points
     * @return array<int, array{lat: float, lng: float, from: string, to: string, minutes: int}>
     */
    public function stops(Collection $points): array
    {
        $min = (int) WorkshopSettings::get('hr.tracking.stop_minutes');
        $stops = [];
        $group = [];

        $flush = function () use (&$group, &$stops, $min) {
            if (count($group) >= 2) {
                $minutes = (int) floor($group[0]->recorded_at->diffInSeconds(end($group)->recorded_at, true) / 60);
                if ($minutes >= $min) {
                    $stops[] = [
                        'lat' => round(collect($group)->avg('lat'), 7),
                        'lng' => round(collect($group)->avg('lng'), 7),
                        'from' => $group[0]->recorded_at->format('H:i'),
                        'to' => end($group)->recorded_at->format('H:i'),
                        'minutes' => $minutes,
                    ];
                }
            }
            $group = [];
        };

        foreach ($points as $p) {
            if ($group && Geo::distance($group[0]->lat, $group[0]->lng, $p->lat, $p->lng) > 100) {
                $flush();
            }
            $group[] = $p;
        }
        $flush();

        return $stops;
    }

    /**
     * Stretches with no position for longer than the "no signal" time (phone off, app closed).
     *
     * @param Collection<int, StaffLocation> $points
     */
    public function gaps(Collection $points): array
    {
        $stale = max(1, (int) WorkshopSettings::get('hr.tracking.stale_minutes'));
        $gaps = [];
        $prev = null;
        foreach ($points as $p) {
            if ($prev) {
                $minutes = (int) floor($prev->recorded_at->diffInSeconds($p->recorded_at, true) / 60);
                if ($minutes > $stale) {
                    $gaps[] = ['from' => $prev->recorded_at->format('H:i'), 'to' => $p->recorded_at->format('H:i'), 'minutes' => $minutes];
                }
            }
            $prev = $p;
        }

        return $gaps;
    }

    protected function homeBranchId(User $user): ?int
    {
        return EmployeeProfile::where('user_id', $user->id)->value('branch_id') ?? $user->branches()->value('branches.id');
    }
}
