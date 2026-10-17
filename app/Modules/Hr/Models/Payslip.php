<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'user_id', 'branch_id', 'days_in_month', 'paid_days', 'lop_days', 'present_days', 'leave_days',
        'off_day_works', 'ot_minutes', 'monthly_salary', 'gross', 'deductions', 'employer_cost', 'net', 'has_edits',
        'pay_status', 'paid_on', 'pay_method', 'pay_reference', 'paid_by',
    ];

    protected $casts = [
        'days_in_month' => 'integer',
        'paid_days' => 'float',
        'lop_days' => 'float',
        'present_days' => 'float',
        'leave_days' => 'float',
        'off_day_works' => 'integer',
        'ot_minutes' => 'integer',
        'monthly_salary' => 'float',
        'gross' => 'float',
        'deductions' => 'float',
        'employer_cost' => 'float',
        'net' => 'float',
        'has_edits' => 'boolean',
        'paid_on' => 'date',
    ];

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Totals from the lines.
     */
    public function recalculateTotals(): void
    {
        $lines = $this->lines()->get();
        $this->gross = round((float) $lines->where('kind', 'earning')->sum('amount'), 2);
        $this->deductions = round((float) $lines->where('kind', 'deduction')->sum('amount'), 2);
        $this->employer_cost = round((float) $lines->where('kind', 'employer')->sum('amount'), 2);
        $this->net = round($this->gross - $this->deductions, 2);
        $this->save();
    }
}
