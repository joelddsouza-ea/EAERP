<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\User;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A person's working hours over a period: their shift (or the default shift) on each day,
 * leaving out the weekly off, holidays and approved leave. The break is not a fixed time,
 * so each window carries a factor (shift minutes after the break ÷ shift length) to apply
 * to any time measured inside it. Used by the technician working-time report.
 */
class WorkingHours
{
    /** No shift in the masters: the usual workshop day */
    private const FALLBACK = ['start' => '09:30', 'end' => '19:00', 'break' => 30];

    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
    ) {}

    /**
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable, 2: float}> [start, end, factor]
     */
    public function windows(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $profile = EmployeeProfile::with(['shift', 'branch'])->where('user_id', $user->id)->first();
        $branchId = $profile?->branch_id ?? $this->attendance->homeBranchId($user);
        $shift = $profile?->effectiveShift() ?? Shift::defaultShift();
        [$start, $end, $break] = $shift
            ? [substr((string) $shift->start_time, 0, 5), substr((string) $shift->end_time, 0, 5), (int) $shift->break_minutes]
            : [self::FALLBACK['start'], self::FALLBACK['end'], self::FALLBACK['break']];

        $holidays = Holiday::forBranch($branchId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->flip();

        $windows = [];
        for ($day = CarbonImmutable::parse($from->toDateString()); $day->lte($to); $day = $day->addDay()) {
            if ($this->attendance->isWeeklyOff($day->toMutable(), $profile, $branchId) || isset($holidays[$day->toDateString()])) {
                continue;
            }
            $leave = $this->leave->leaveOn($user->id, $day->toDateString());
            if ($leave && empty($leave['half'])) {
                continue;
            }

            $s = $day->setTimeFromTimeString($start);
            $e = $day->setTimeFromTimeString($end);
            $length = max(1, $s->diffInMinutes($e));
            $factor = max(0, $length - $break) / $length;
            // Half a day off: half the working time counts
            if ($leave) {
                $factor /= 2;
            }
            $windows[] = [$s, $e, $factor];
        }

        return $windows;
    }
}
