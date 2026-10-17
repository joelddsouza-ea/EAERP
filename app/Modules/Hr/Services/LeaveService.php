<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Workshop\Notifications\StaffAlert;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Paid and unpaid leave. A leave counts working days only (weekly offs and holidays in
 * the range are not taken off the balance). Paid leave beyond the balance is taken as
 * leave without pay; an absence without approved leave is also unpaid.
 */
class LeaveService
{
    // ------------------------------------------------------------- balances

    /**
     * Each active leave type's balance for the year: allotted (+ carried) − approved − pending.
     *
     * @return Collection<int, array>
     */
    public function balances(int $userId, ?int $year = null): Collection
    {
        $year ??= (int) now()->year;

        return LeaveType::where('is_active', true)->orderBy('sort_order')->get()->map(function (LeaveType $type) use ($userId, $year) {
            $balance = $this->balanceRow($userId, $type, $year);
            $taken = $this->taken($userId, $type->id, $year, [LeaveRequest::APPROVED]);
            $pending = $this->taken($userId, $type->id, $year, [LeaveRequest::PENDING]);
            $entitled = $type->is_paid ? round($balance->allotted + $balance->carried, 1) : null;

            return [
                'leave_type_id' => $type->id,
                'name' => $type->name,
                'code' => $type->code,
                'is_paid' => $type->is_paid,
                'allotted' => $type->is_paid ? $balance->allotted : null,
                'carried' => $type->is_paid ? $balance->carried : null,
                'taken' => $taken,
                'pending' => $pending,
                'available' => $entitled !== null ? max(0, round($entitled - $taken - $pending, 1)) : null,
            ];
        })->values();
    }

    /**
     * The year's balance row, created on first use: the quota (pro rata in the joining
     * year) plus what is carried from last year where the type allows it.
     */
    public function balanceRow(int $userId, LeaveType $type, int $year): LeaveBalance
    {
        $row = LeaveBalance::where(['user_id' => $userId, 'leave_type_id' => $type->id, 'year' => $year])->first();
        if ($row) {
            return $row;
        }

        $allotted = (float) $type->annual_quota;
        $joined = EmployeeProfile::where('user_id', $userId)->value('date_joined');
        if ($joined && Carbon::parse($joined)->year === $year) {
            $months = 12 - Carbon::parse($joined)->month + 1;
            $allotted = floor($allotted * $months / 12 * 2) / 2;
        }

        $carried = 0.0;
        if ($type->carry_forward && $type->is_paid) {
            $prev = LeaveBalance::where(['user_id' => $userId, 'leave_type_id' => $type->id, 'year' => $year - 1])->first();
            if ($prev) {
                $left = $prev->allotted + $prev->carried - $this->taken($userId, $type->id, $year - 1, [LeaveRequest::APPROVED]);
                $carried = max(0, min((float) $type->max_carry, $left));
            }
        }

        return LeaveBalance::create([
            'user_id' => $userId,
            'leave_type_id' => $type->id,
            'year' => $year,
            'allotted' => $allotted,
            'carried' => $carried,
        ]);
    }

    /**
     * Paid days used in a year (unpaid days beyond the balance don't use it).
     */
    protected function taken(int $userId, int $typeId, int $year, array $statuses): float
    {
        return round((float) LeaveRequest::where('user_id', $userId)
            ->where('leave_type_id', $typeId)
            ->whereIn('status', $statuses)
            ->whereYear('from_date', $year)
            ->sum(DB::raw('days - unpaid_days')), 1);
    }

    // ---------------------------------------------------------------- apply

    public function apply(User $user, array $data): LeaveRequest
    {
        $type = LeaveType::where('is_active', true)->findOrFail($data['leave_type_id']);
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'] ?? $data['from_date'])->startOfDay();
        $half = (bool) ($data['half_day'] ?? false);

        if ($to->lt($from)) {
            throw new HrException('The leave ends before it starts.', ['to_date' => ['Before the start date.']]);
        }
        if ($half && !$from->eq($to)) {
            throw new HrException('A half day is a single day.', ['half_day' => ['Choose one day.']]);
        }
        if ($from->year !== $to->year) {
            throw new HrException('Apply separately for each year.', ['to_date' => ['Split the leave at 31 December.']]);
        }

        $overlap = LeaveRequest::where('user_id', $user->id)
            ->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
            ->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())
            ->exists();
        if ($overlap) {
            throw new HrException('You already have leave on these dates.');
        }

        $days = $half ? 0.5 : (float) count($this->workingDates($user->id, $from, $to));
        if ($days <= 0) {
            throw new HrException('These dates are weekly offs or holidays already.');
        }

        $available = $type->is_paid ? (float) ($this->balances($user->id, $from->year)->firstWhere('leave_type_id', $type->id)['available'] ?? 0) : 0.0;
        $unpaid = $type->is_paid ? max(0, round($days - $available, 1)) : $days;

        $request = LeaveRequest::create([
            'user_id' => $user->id,
            'branch_id' => app(AttendanceService::class)->homeBranchId($user),
            'leave_type_id' => $type->id,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'half_day' => $half,
            'days' => $days,
            'unpaid_days' => $unpaid,
            'reason' => $data['reason'] ?? null,
            'status' => LeaveRequest::PENDING,
        ]);

        $when = $from->eq($to) ? $from->format('d M') . ($half ? ' (half day)' : '') : $from->format('d M') . ' – ' . $to->format('d M');
        $alert = new StaffAlert(
            'leave_requested',
            "Leave request — {$user->name}",
            "{$type->name}, {$when}" . ($unpaid > 0 ? " ({$unpaid} day(s) without pay)" : '') . ($request->reason ? ": {$request->reason}" : ''),
            '/hr/leave',
        );
        foreach ($this->approvers($request) as $approver) {
            $approver->notify($alert);
        }

        return $request;
    }

    /**
     * Managers of the employee's branch (and the owner) approve leave.
     *
     * @return Collection<int, User>
     */
    public function approvers(LeaveRequest $request): Collection
    {
        return User::active()
            ->where('id', '!=', $request->user_id)
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'hr.leave.approve'))
            ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('slug', Role::OWNER))
                ->when($request->branch_id, fn ($q) => $q->orWhereHas('branches', fn ($b) => $b->where('branches.id', $request->branch_id))))
            ->get();
    }

    public function decide(LeaveRequest $request, bool $approve, ?string $note, User $by): LeaveRequest
    {
        if ($request->status !== LeaveRequest::PENDING) {
            throw new HrException('This request has already been answered.');
        }
        if ($request->user_id === $by->id && !$by->isOwner()) {
            throw new HrException('Someone else approves your own leave.', [], 403);
        }

        DB::transaction(function () use ($request, $approve, $note, $by) {
            $request->update([
                'status' => $approve ? LeaveRequest::APPROVED : LeaveRequest::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            if ($approve) {
                $this->refreshDays($request);
            }
        });

        $request->user?->notify(new StaffAlert(
            'leave_decided',
            'Leave ' . ($approve ? 'approved' : 'not approved'),
            $request->type->name . ', ' . $request->from_date->format('d M') . ($request->from_date->ne($request->to_date) ? ' – ' . $request->to_date->format('d M') : '') . ($note ? ": {$note}" : ''),
            '/attendance',
        ));

        return $request->fresh(['type', 'user', 'decidedBy']);
    }

    /**
     * The employee withdraws a pending request, or a manager cancels approved leave
     * (before its month's payroll is finalised).
     */
    public function cancel(LeaveRequest $request, User $by): LeaveRequest
    {
        if (!in_array($request->status, [LeaveRequest::PENDING, LeaveRequest::APPROVED], true)) {
            throw new HrException('This request cannot be cancelled.');
        }
        if ($request->status === LeaveRequest::APPROVED && !$by->hasPermission('hr.leave.approve')) {
            throw new HrException('Ask your manager to cancel approved leave.', [], 403);
        }
        app(AttendanceService::class)->assertOpen($request->user_id, $request->from_date->toDateString());

        DB::transaction(function () use ($request, $by) {
            $wasApproved = $request->status === LeaveRequest::APPROVED;
            $request->update(['status' => LeaveRequest::CANCELLED, 'decided_by' => $by->id, 'decided_at' => now()]);
            if ($wasApproved) {
                $this->refreshDays($request);
            }
        });

        return $request->fresh(['type', 'user']);
    }

    protected function refreshDays(LeaveRequest $request): void
    {
        app(AttendanceService::class)->rebuildRange($request->user_id, $request->from_date->toDateString(), $request->to_date->toDateString());
    }

    // -------------------------------------------------------- per day

    /**
     * Approved leave on a date: paid or unpaid (the last days of a request beyond the
     * balance are unpaid), and whether it is a half day.
     *
     * @return array{request_id: int, portion: string, half: bool}|null
     */
    public function leaveOn(int $userId, string $date): ?array
    {
        $request = LeaveRequest::with('type')
            ->where('user_id', $userId)
            ->where('status', LeaveRequest::APPROVED)
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->first();
        if (!$request) {
            return null;
        }

        if ($request->half_day) {
            return ['request_id' => $request->id, 'portion' => $request->type->is_paid && $request->unpaid_days < 0.5 ? 'paid' : 'unpaid', 'half' => true];
        }

        $dates = $this->workingDates($userId, $request->from_date, $request->to_date);
        $index = array_search($date, $dates, true);
        if ($index === false) {
            // A weekly off or holiday inside the leave
            return null;
        }
        $paidDays = $request->type->is_paid ? (int) floor($request->days - $request->unpaid_days) : 0;

        return ['request_id' => $request->id, 'portion' => $index < $paidDays ? 'paid' : 'unpaid', 'half' => false];
    }

    /**
     * Working dates (not weekly off, not holiday) in a range.
     *
     * @return array<int, string>
     */
    public function workingDates(int $userId, Carbon $from, Carbon $to): array
    {
        $profile = EmployeeProfile::with('branch')->where('user_id', $userId)->first();
        $branchId = $profile?->branch_id;
        $holidays = Holiday::forBranch($branchId)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $attendance = app(AttendanceService::class);

        $dates = [];
        foreach (CarbonPeriod::create($from, $to) as $d) {
            if (!$attendance->isWeeklyOff($d, $profile, $branchId) && !in_array($d->toDateString(), $holidays, true)) {
                $dates[] = $d->toDateString();
            }
        }

        return $dates;
    }

    public function present(LeaveRequest $r): array
    {
        $r->loadMissing(['type', 'user:id,name', 'decidedBy:id,name']);

        return [
            'id' => $r->id,
            'user_id' => $r->user_id,
            'employee' => $r->user?->name,
            'leave_type' => $r->type?->name,
            'leave_type_id' => $r->leave_type_id,
            'is_paid' => $r->type?->is_paid,
            'from_date' => $r->from_date->toDateString(),
            'to_date' => $r->to_date->toDateString(),
            'half_day' => $r->half_day,
            'days' => $r->days,
            'unpaid_days' => $r->unpaid_days,
            'reason' => $r->reason,
            'status' => $r->status,
            'decided_by' => $r->decidedBy?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
