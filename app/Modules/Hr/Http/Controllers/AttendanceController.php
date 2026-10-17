<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Hr\Models\AttendanceDay;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Attendance register for managers: who is in today, a month grid, and regularisation.
 */
class AttendanceController extends ApiController
{
    public function __construct(protected AttendanceService $attendance) {}

    /**
     * Everyone's day: status, in / out, the selfies.
     */
    public function day(Request $request): JsonResponse
    {
        $date = $request->query('date', today()->toDateString());
        $branchId = $request->integer('branch_id') ?: null;
        if ($date < today()->toDateString()) {
            $this->attendance->closeDay($date);
        }

        $profiles = $this->profiles($request, $branchId, $date);
        $days = AttendanceDay::where('date', $date)->whereIn('user_id', $profiles->pluck('user_id'))->get()->keyBy('user_id');
        $punches = AttendancePunch::with(['branch:id,name', 'createdBy:id,name'])
            ->where('work_date', $date)
            ->whereIn('user_id', $profiles->pluck('user_id'))
            ->orderBy('punched_at')
            ->get()
            ->groupBy('user_id');

        $rows = $profiles->map(fn (EmployeeProfile $p) => [
            'user_id' => $p->user_id,
            'name' => $p->user?->name,
            'designation' => $p->designation,
            'branch' => $p->branch?->name,
            'day' => $this->attendance->presentDay($days[$p->user_id] ?? null),
            'punches' => ($punches[$p->user_id] ?? collect())->map(fn ($x) => $this->attendance->presentPunch($x))->values(),
        ])->sortBy('name')->values();

        $statuses = $rows->map(fn ($r) => $r['day']['status'] ?? null);

        return $this->success([
            'date' => $date,
            'rows' => $rows,
            'counts' => [
                'employees' => $rows->count(),
                'in_now' => $rows->filter(fn ($r) => ($r['punches']->where('voided', false)->last()['type'] ?? null) === 'in')->count(),
                'present' => $statuses->filter(fn ($s) => in_array($s, [AttendanceDay::PRESENT, AttendanceDay::HALF_DAY, AttendanceDay::INCOMPLETE], true))->count(),
                'absent' => $statuses->filter(fn ($s) => $s === AttendanceDay::ABSENT || $s === null)->count(),
                'on_leave' => $statuses->filter(fn ($s) => in_array($s, [AttendanceDay::LEAVE_PAID, AttendanceDay::LEAVE_UNPAID], true))->count(),
            ],
        ]);
    }

    /**
     * A month grid: one row per employee, one cell per day.
     */
    public function month(Request $request): JsonResponse
    {
        $period = $request->query('period', now()->format('Y-m'));
        $from = $period . '-01';
        $to = date('Y-m-t', strtotime($from));
        $profiles = $this->profiles($request, $request->integer('branch_id') ?: null, $from);

        $days = AttendanceDay::whereBetween('date', [$from, $to])->whereIn('user_id', $profiles->pluck('user_id'))->get()->groupBy('user_id');

        return $this->success([
            'period' => $period,
            'days_in_month' => (int) date('t', strtotime($from)),
            'rows' => $profiles->map(fn (EmployeeProfile $p) => [
                'user_id' => $p->user_id,
                'name' => $p->user?->name,
                'days' => ($days[$p->user_id] ?? collect())->mapWithKeys(fn (AttendanceDay $d) => [(int) $d->date->format('j') => [
                    'status' => $d->status,
                    'ot' => $d->ot_minutes,
                    'late' => $d->late_minutes,
                    'off_work' => $d->worked_off_day,
                    'regularised' => $d->regularised,
                ]]),
            ])->sortBy('name')->values(),
        ]);
    }

    /**
     * One employee's punches on a day (with removed ones, for the record).
     */
    public function punches(Request $request, User $user): JsonResponse
    {
        $date = $request->input('date', today()->toDateString());

        return $this->success([
            'employee' => $user->name,
            'date' => $date,
            'day' => $this->attendance->presentDay(AttendanceDay::where('user_id', $user->id)->where('date', $date)->first()),
            'punches' => AttendancePunch::with(['branch:id,name', 'createdBy:id,name'])->where('user_id', $user->id)->where('work_date', $date)
                ->orderBy('punched_at')->get()->map(fn ($p) => $this->attendance->presentPunch($p))->values(),
        ]);
    }

    public function addPunch(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
            'type' => 'required|in:in,out',
            'time' => 'required|date_format:H:i',
            'reason' => 'required|string|max:500',
        ]);

        $this->attendance->addManualPunch($user, $data['date'], $data['type'], $data['time'], $data['reason'], $request->user());

        return $this->punches($request->merge(['date' => $data['date']]), $user);
    }

    public function voidPunch(Request $request, AttendancePunch $punch): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->attendance->voidPunch($punch, $data['reason'], $request->user());

        return $this->punches($request->merge(['date' => $punch->work_date->toDateString()]), $punch->user);
    }

    /**
     * Employees on attendance the viewer may see (their branches unless owner).
     */
    protected function profiles(Request $request, ?int $branchId, string $date)
    {
        $user = $request->user();
        $branches = $user->isOwner() ? null : $user->getAccessibleBranchIds();

        return EmployeeProfile::with(['user:id,name,is_active', 'branch:id,name'])
            ->where('attendance_required', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->where(fn ($q) => $q->whereNull('date_left')->orWhere('date_left', '>=', $date))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches))
            ->get();
    }
}
