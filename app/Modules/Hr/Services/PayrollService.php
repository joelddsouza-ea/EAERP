<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Hr\Models\AttendanceDay;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\PayrollAdjustment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll:
 *  - per day = monthly salary ÷ days in the month; weekly offs, holidays and paid leave
 *    are paid; absence and leave without pay are not (loss of pay);
 *  - OT (only the time after the first hour past the shift end) × the hourly OT rate;
 *    an extra amount for each weekly off / holiday worked;
 *  - incentives (rules + manual), bonus, reimbursements;
 *  - deductions: manual deductions and penalties, PT / PF / ESI when switched on,
 *    salary-advance recovery and customer money the employee still holds;
 *  - draft → finalised (attendance, recoveries and the books are fixed) → paid.
 */
class PayrollService
{
    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
        protected IncentiveService $incentives,
    ) {}

    // ------------------------------------------------------------------ runs

    public function createRun(string $period, ?int $branchId, User $by): PayrollRun
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new HrException('Choose the month.', ['period' => ['Format YYYY-MM.']]);
        }
        if ($period > now()->format('Y-m')) {
            throw new HrException('That month has not started yet.');
        }

        $clash = PayrollRun::where('period', $period)
            ->where(fn ($q) => $branchId ? $q->whereNull('branch_id')->orWhere('branch_id', $branchId) : $q->whereRaw('1 = 1'))
            ->first();
        if ($clash) {
            throw new HrException('Payroll for ' . Carbon::parse($period . '-01')->format('F Y') . ($clash->branch_id ? ' (' . $clash->branch?->name . ')' : '') . ' already exists. Open it instead.');
        }

        $run = PayrollRun::create(['period' => $period, 'branch_id' => $branchId, 'status' => PayrollRun::DRAFT, 'created_by' => $by->id]);
        $this->calculate($run);

        return $run->fresh();
    }

    /**
     * (Re)work out every payslip of a draft run. Lines edited by hand keep their amounts.
     */
    public function calculate(PayrollRun $run): PayrollRun
    {
        if (!$run->isDraft()) {
            throw new HrException('A finalised payroll cannot be recalculated. Reopen it first.');
        }

        $profiles = $this->employees($run);
        $incentives = $this->incentives->forPeriod($run->period);

        DB::transaction(function () use ($run, $profiles, $incentives) {
            $keep = [];
            foreach ($profiles as $profile) {
                $slip = $this->calculateSlip($run, $profile, $incentives[$profile->user_id] ?? []);
                $keep[] = $slip->id;
            }
            // Employees no longer in this run (left, moved branch, salary removed)
            $run->payslips()->whereNotIn('id', $keep)->get()->each(function (Payslip $s) {
                $s->lines()->delete();
                $s->delete();
            });

            $this->refreshRunTotals($run);
            $run->update(['calculated_at' => now()]);
        });

        return $run->fresh();
    }

    /**
     * Employees paid in this run: with a salary, employed in the month, not in another run.
     *
     * @return Collection<int, EmployeeProfile>
     */
    public function employees(PayrollRun $run): Collection
    {
        $start = $run->periodStart();
        $end = $run->periodEnd();
        $elsewhere = Payslip::whereHas('run', fn ($q) => $q->where('period', $run->period)->where('id', '!=', $run->id))->pluck('user_id');

        return EmployeeProfile::with(['user', 'shift', 'branch'])
            ->where('monthly_salary', '>', 0)
            ->where(fn ($q) => $q->whereNull('date_joined')->orWhere('date_joined', '<=', $end))
            ->where(fn ($q) => $q->whereNull('date_left')->orWhere('date_left', '>=', $start))
            ->whereHas('user', fn ($q) => $q->whereNull('deleted_at'))
            ->when($run->branch_id, fn ($q) => $q->where('branch_id', $run->branch_id))
            ->whereNotIn('user_id', $elsewhere)
            ->get();
    }

    protected function calculateSlip(PayrollRun $run, EmployeeProfile $profile, array $incentives): Payslip
    {
        $start = Carbon::parse($run->periodStart());
        $end = Carbon::parse($run->periodEnd());
        $daysInMonth = $start->daysInMonth;

        $employedFrom = $profile->date_joined && $profile->date_joined->gt($start) ? $profile->date_joined->copy() : $start->copy();
        $employedTo = $profile->date_left && $profile->date_left->lt($end) ? $profile->date_left->copy() : $end->copy();

        // Days up to today are rebuilt from punches and leave; later days count as paid
        $this->attendance->rebuildRange($profile->user_id, $employedFrom->toDateString(), $employedTo->toDateString());
        $days = AttendanceDay::where('user_id', $profile->user_id)
            ->whereBetween('date', [$employedFrom->toDateString(), $employedTo->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceDay $d) => $d->date->toDateString());

        $paid = 0.0;
        $lop = 0.0;
        $present = 0.0;
        $leaveDays = 0.0;
        $offWorks = 0;
        $ot = 0;

        foreach (CarbonPeriod::create($employedFrom, $employedTo) as $date) {
            $key = $date->toDateString();
            $day = $days[$key] ?? null;

            if (!$profile->attendance_required || !$day) {
                // Not kept on attendance, or a day still ahead
                $paid += 1;
                continue;
            }

            $ot += $day->ot_minutes;
            if ($day->worked_off_day) {
                $offWorks++;
            }

            switch ($day->status) {
                case AttendanceDay::PRESENT:
                case AttendanceDay::INCOMPLETE:
                    $paid += 1;
                    $present += 1;
                    break;
                case AttendanceDay::HALF_DAY:
                    $paid += 0.5;
                    $present += 0.5;
                    $leave = $this->leave->leaveOn($profile->user_id, $key);
                    if ($leave && $leave['half'] && $leave['portion'] === 'paid') {
                        $paid += 0.5;
                        $leaveDays += 0.5;
                    } else {
                        $lop += 0.5;
                    }
                    break;
                case AttendanceDay::LEAVE_PAID:
                    $leave = $this->leave->leaveOn($profile->user_id, $key);
                    $portion = $leave && $leave['half'] ? 0.5 : 1.0;
                    $paid += $portion;
                    $leaveDays += $portion;
                    $lop += 1 - $portion;
                    break;
                case AttendanceDay::WEEKLY_OFF:
                case AttendanceDay::HOLIDAY:
                    $paid += 1;
                    break;
                default:
                    // absent, leave without pay
                    $lop += 1;
            }
        }

        $salary = (float) $profile->monthly_salary;
        $perDay = $salary / $daysInMonth;

        /** @var Payslip $slip */
        $slip = Payslip::firstOrNew(['payroll_run_id' => $run->id, 'user_id' => $profile->user_id]);
        $edited = $slip->exists
            ? $slip->lines()->whereNotNull('edited_note')->get()->keyBy(fn (PayslipLine $l) => $this->lineKey($l->code, $l->source_type, $l->source_id))
            : collect();

        $slip->fill([
            'branch_id' => $profile->branch_id,
            'days_in_month' => $daysInMonth,
            'paid_days' => $paid,
            'lop_days' => $lop,
            'present_days' => $present,
            'leave_days' => $leaveDays,
            'off_day_works' => $offWorks,
            'ot_minutes' => $ot,
            'monthly_salary' => $salary,
        ])->save();

        $lines = [];
        $add = function (string $code, string $label, string $kind, float $amount, ?string $sourceType = null, ?int $sourceId = null) use (&$lines, $edited) {
            $key = $this->lineKey($code, $sourceType, $sourceId);
            $override = $edited[$key] ?? null;
            $amount = $override ? (float) $override->amount : round($amount, 2);
            if ($amount == 0.0 && !$override) {
                return 0.0;
            }
            $lines[] = [
                'code' => $code, 'label' => $label, 'kind' => $kind, 'amount' => $amount,
                'source_type' => $sourceType, 'source_id' => $sourceId, 'edited_note' => $override?->edited_note,
            ];

            return $amount;
        };

        // Earnings
        $paidLabel = rtrim(rtrim(number_format($paid, 1), '0'), '.');
        $add('salary', "Salary ({$paidLabel} of {$daysInMonth} days)", 'earning', $perDay * $paid);
        if ($ot > 0) {
            $hours = round($ot / 60, 2);
            $add('ot', "Overtime ({$hours} h × Rs " . number_format($profile->otRate(), 0) . ')', 'earning', $hours * $profile->otRate());
        }
        if ($offWorks > 0) {
            $add('offday', "Worked on weekly off / holiday ({$offWorks} × Rs " . number_format($profile->offdayAmount(), 0) . ')', 'earning', $offWorks * $profile->offdayAmount());
        }
        foreach ($incentives as $inc) {
            $add('incentive', $inc['label'], 'earning', $inc['amount'], 'incentive_rule', $inc['rule_id']);
        }
        $adjustments = PayrollAdjustment::where('user_id', $profile->user_id)->where('period', $run->period)->orderBy('id')->get();
        foreach ($adjustments->filter->isEarning() as $adj) {
            $add($adj->kind, PayrollAdjustment::EARNINGS[$adj->kind] . ($adj->note ? " — {$adj->note}" : ''), 'earning', $adj->amount, 'adjustment', $adj->id);
        }
        $gross = round(collect($lines)->where('kind', 'earning')->sum('amount'), 2);

        // Statutory
        if ($this->statutoryOn()) {
            if ($profile->pt_enabled) {
                $add('pt', 'Professional tax', 'deduction', $this->professionalTax($gross, $profile->gender, (int) $start->month));
            }
            if ($profile->pf_enabled) {
                $wage = $profile->pfWage() * $paid / $daysInMonth;
                $ceiling = (float) $this->setting('pf_wage_ceiling');
                if ($ceiling > 0) {
                    $wage = min($wage, $ceiling);
                }
                $add('pf', 'Provident fund (' . $this->setting('pf_employee_rate') . '%)', 'deduction', round($wage * (float) $this->setting('pf_employee_rate') / 100));
                $add('pf_employer', 'Employer PF (' . $this->setting('pf_employer_rate') . '%)', 'employer', round($wage * (float) $this->setting('pf_employer_rate') / 100));
            }
            if ($profile->esi_enabled && $salary <= (float) $this->setting('esi_gross_limit')) {
                $add('esi', 'ESI (' . $this->setting('esi_employee_rate') . '%)', 'deduction', ceil($gross * (float) $this->setting('esi_employee_rate') / 100));
                $add('esi_employer', 'Employer ESI (' . $this->setting('esi_employer_rate') . '%)', 'employer', ceil($gross * (float) $this->setting('esi_employer_rate') / 100));
            }
        }

        foreach ($adjustments->reject->isEarning() as $adj) {
            $add($adj->kind, PayrollAdjustment::DEDUCTIONS[$adj->kind] . ($adj->note ? " — {$adj->note}" : ''), 'deduction', $adj->amount, 'adjustment', $adj->id);
        }

        // Recoveries, only out of what is left to pay
        $room = fn () => max(0, round($gross - collect($lines)->where('kind', 'deduction')->sum('amount'), 2));
        foreach (SalaryAdvance::where('user_id', $profile->user_id)->where('status', SalaryAdvance::OPEN)->where('given_on', '<=', $run->periodEnd())->orderBy('given_on')->get() as $advance) {
            $due = $advance->recover_per_month > 0 ? min($advance->recover_per_month, $advance->balance()) : $advance->balance();
            $add('advance', 'Salary advance of ' . $advance->given_on->format('d M Y') . ' (balance Rs ' . number_format($advance->balance(), 0) . ')', 'deduction', min($due, $room()), 'salary_advance', $advance->id);
        }
        $held = StaffCustodyEntry::balanceFor($profile->user_id, $run->periodEnd());
        if ($held > 0) {
            $add('custody', 'Customer money not handed over (Rs ' . number_format($held, 0) . ')', 'deduction', min($held, $room()));
        }

        $slip->lines()->delete();
        foreach ($lines as $i => $line) {
            $slip->lines()->create([...$line, 'sort_order' => $i]);
        }
        $slip->has_edits = collect($lines)->whereNotNull('edited_note')->isNotEmpty();
        $slip->recalculateTotals();

        return $slip;
    }

    /**
     * Back office corrects one line (with a reason); the totals follow. The change is
     * kept when the payroll is recalculated.
     */
    public function editLine(PayslipLine $line, float $amount, string $note): Payslip
    {
        $slip = $line->payslip()->with('run')->firstOrFail();
        if (!$slip->run->isDraft()) {
            throw new HrException('Reopen the payroll to change it.');
        }
        if (!trim($note)) {
            throw new HrException('Say why the amount is changed.', ['note' => ['Required.']]);
        }

        $line->update(['amount' => round($amount, 2), 'edited_note' => $note]);
        $slip->has_edits = true;
        $slip->recalculateTotals();
        $this->refreshRunTotals($slip->run);

        return $slip->fresh('lines');
    }

    /**
     * The owner signs the payroll off: attendance of the month is locked, advances and
     * customer money are recovered, and the salary cost goes on the books.
     */
    public function finalise(PayrollRun $run, User $by, bool $force = false): PayrollRun
    {
        if (!$run->isDraft()) {
            throw new HrException('This payroll is already finalised.');
        }
        if ($run->periodEnd() >= today()->toDateString()) {
            throw new HrException(Carbon::parse($run->periodStart())->format('F Y') . ' is not over yet.');
        }

        $this->calculate($run);
        $userIds = $run->payslips()->pluck('user_id');

        $incomplete = AttendanceDay::with('user:id,name')
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$run->periodStart(), $run->periodEnd()])
            ->where('status', AttendanceDay::INCOMPLETE)
            ->get();
        if ($incomplete->isNotEmpty() && !$force) {
            $list = $incomplete->take(5)->map(fn ($d) => $d->user?->name . ' on ' . $d->date->format('d M'))->implode(', ');
            throw new HrException("Out-punch missing: {$list}" . ($incomplete->count() > 5 ? ' and more' : '') . '. Fix them in the attendance register first.');
        }

        DB::transaction(function () use ($run, $by) {
            foreach ($run->payslips()->with('lines')->get() as $slip) {
                foreach ($slip->lines as $line) {
                    if ($line->code === 'advance' && $line->source_id) {
                        $advance = SalaryAdvance::lockForUpdate()->find($line->source_id);
                        if ($advance) {
                            $recovered = round($advance->recovered + $line->amount, 2);
                            $advance->update(['recovered' => $recovered, 'status' => $recovered >= $advance->amount - 0.005 ? SalaryAdvance::CLOSED : SalaryAdvance::OPEN]);
                        }
                    }
                    if ($line->code === 'custody' && $line->amount > 0) {
                        StaffCustodyEntry::create([
                            'user_id' => $slip->user_id,
                            'branch_id' => $slip->branch_id,
                            'entry_date' => $run->periodEnd(),
                            'amount' => -$line->amount,
                            'kind' => StaffCustodyEntry::SALARY_RECOVERY,
                            'payslip_id' => $slip->id,
                            'note' => 'Recovered from salary ' . $run->period,
                            'created_by' => $by->id,
                        ]);
                    }
                }
            }

            $run->update(['status' => PayrollRun::FINALISED, 'finalised_by' => $by->id, 'finalised_at' => now()]);
        });

        return $run->fresh();
    }

    /**
     * Undo the finalisation (nothing paid yet): recoveries are put back.
     */
    public function reopen(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRun::FINALISED) {
            throw new HrException('Only a finalised, unpaid payroll can be reopened.');
        }
        if ($run->payslips()->where('pay_status', 'paid')->exists()) {
            throw new HrException('Some salaries are already paid; this payroll can no longer be reopened.');
        }

        DB::transaction(function () use ($run) {
            foreach ($run->payslips()->with('lines')->get() as $slip) {
                foreach ($slip->lines->where('code', 'advance') as $line) {
                    $advance = SalaryAdvance::lockForUpdate()->find($line->source_id);
                    if ($advance) {
                        $advance->update(['recovered' => max(0, round($advance->recovered - $line->amount, 2)), 'status' => SalaryAdvance::OPEN]);
                    }
                }
                StaffCustodyEntry::where('payslip_id', $slip->id)->get()->each->delete();
            }
            $run->update(['status' => PayrollRun::DRAFT, 'finalised_by' => null, 'finalised_at' => null]);
        });

        return $run->fresh();
    }

    /**
     * Salary paid out (one payslip, or all unpaid ones of the run).
     *
     * @param array{method: string, paid_on?: ?string, reference?: ?string} $data
     */
    public function markPaid(PayrollRun $run, array $payslipIds, array $data, User $by): PayrollRun
    {
        if ($run->isDraft()) {
            throw new HrException('Finalise the payroll before paying salaries.');
        }

        DB::transaction(function () use ($run, $payslipIds, $data, $by) {
            $slips = $run->payslips()->where('pay_status', 'unpaid')->when($payslipIds, fn ($q) => $q->whereIn('id', $payslipIds))->get();
            foreach ($slips as $slip) {
                $slip->update([
                    'pay_status' => 'paid',
                    'paid_on' => $data['paid_on'] ?? today()->toDateString(),
                    'pay_method' => $data['method'],
                    'pay_reference' => $data['reference'] ?? null,
                    'paid_by' => $by->id,
                ]);
            }
            if (!$run->payslips()->where('pay_status', 'unpaid')->exists()) {
                $run->update(['status' => PayrollRun::PAID]);
            }
        });

        return $run->fresh();
    }

    /**
     * An employee's attendance for the month can't change once their payroll is finalised.
     */
    public function isLocked(int $userId, string $period): bool
    {
        return Payslip::where('user_id', $userId)
            ->whereHas('run', fn ($q) => $q->where('period', $period)->whereIn('status', [PayrollRun::FINALISED, PayrollRun::PAID]))
            ->exists();
    }

    // -------------------------------------------------------------- statutory

    public function statutoryOn(): bool
    {
        return (bool) WorkshopSettings::get('hr.statutory.enabled');
    }

    /**
     * Maharashtra professional tax on the month's gross.
     */
    public function professionalTax(float $gross, ?string $gender, int $month): float
    {
        $slabs = (array) $this->setting($gender === 'female' ? 'pt_slabs_female' : 'pt_slabs_male');
        $last = count($slabs) - 1;

        foreach ($slabs as $i => [$upTo, $tax]) {
            if ($upTo === null || $gross <= (float) $upTo) {
                // February's top slab is higher (makes the yearly total Rs 2,500)
                return $i === $last && $month === 2 && (float) $tax > 0 ? (float) $this->setting('pt_february_top') : (float) $tax;
            }
        }

        return 0.0;
    }

    protected function setting(string $key): mixed
    {
        return WorkshopSettings::get("hr.statutory.{$key}");
    }

    // ---------------------------------------------------------------- helpers

    protected function lineKey(string $code, ?string $sourceType, ?int $sourceId): string
    {
        return $code . '|' . ($sourceType ?? '') . '|' . ($sourceId ?? '');
    }

    public function refreshRunTotals(PayrollRun $run): void
    {
        $slips = $run->payslips()->get();
        $run->update([
            'gross' => round((float) $slips->sum('gross'), 2),
            'deductions' => round((float) $slips->sum('deductions'), 2),
            'net' => round((float) $slips->sum('net'), 2),
            'employer_cost' => round((float) $slips->sum('employer_cost'), 2),
        ]);
    }

    public function presentRun(PayrollRun $run, bool $withSlips = false): array
    {
        $run->loadMissing(['branch:id,name', 'finalisedBy:id,name']);

        return [
            'id' => $run->id,
            'period' => $run->period,
            'label' => Carbon::parse($run->periodStart())->format('F Y'),
            'branch' => $run->branch?->name ?? 'All branches',
            'branch_id' => $run->branch_id,
            'status' => $run->status,
            'gross' => $run->gross,
            'deductions' => $run->deductions,
            'net' => $run->net,
            'employer_cost' => $run->employer_cost,
            'employees' => $run->payslips()->count(),
            'unpaid' => $run->payslips()->where('pay_status', 'unpaid')->count(),
            'calculated_at' => $run->calculated_at?->toIso8601String(),
            'finalised_by' => $run->finalisedBy?->name,
            'finalised_at' => $run->finalised_at?->toIso8601String(),
            'month_over' => $run->periodEnd() < today()->toDateString(),
            'payslips' => $withSlips
                ? $run->payslips()->with(['lines', 'user:id,name'])->get()->sortBy(fn ($s) => $s->user?->name)->map(fn (Payslip $s) => $this->presentSlip($s))->values()
                : null,
        ];
    }

    public function presentSlip(Payslip $s): array
    {
        $s->loadMissing(['lines', 'user:id,name', 'run']);
        $profile = EmployeeProfile::where('user_id', $s->user_id)->first();

        return [
            'id' => $s->id,
            'run_id' => $s->payroll_run_id,
            'period' => $s->run?->period,
            'period_label' => $s->run ? Carbon::parse($s->run->periodStart())->format('F Y') : null,
            'run_status' => $s->run?->status,
            'user_id' => $s->user_id,
            'employee' => $s->user?->name,
            'employee_code' => $profile?->employee_code,
            'designation' => $profile?->designation,
            'days_in_month' => $s->days_in_month,
            'paid_days' => $s->paid_days,
            'lop_days' => $s->lop_days,
            'present_days' => $s->present_days,
            'leave_days' => $s->leave_days,
            'off_day_works' => $s->off_day_works,
            'ot_hours' => round($s->ot_minutes / 60, 2),
            'monthly_salary' => $s->monthly_salary,
            'gross' => $s->gross,
            'deductions' => $s->deductions,
            'employer_cost' => $s->employer_cost,
            'net' => $s->net,
            'has_edits' => $s->has_edits,
            'pay_status' => $s->pay_status,
            'paid_on' => $s->paid_on?->toDateString(),
            'pay_method' => $s->pay_method,
            'pay_reference' => $s->pay_reference,
            'lines' => $s->lines->map(fn (PayslipLine $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'label' => $l->label,
                'kind' => $l->kind,
                'amount' => $l->amount,
                'edited_note' => $l->edited_note,
            ])->values(),
        ];
    }
}
