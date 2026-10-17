<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's HR and pay setup (one per user).
 */
class EmployeeProfile extends Model
{
    protected $fillable = [
        'user_id', 'branch_id', 'employee_code', 'designation', 'gender', 'date_joined', 'date_left',
        'monthly_salary', 'basic_salary', 'ot_hourly_rate', 'offday_work_amount', 'shift_id', 'weekly_off_day',
        'pt_enabled', 'pf_enabled', 'esi_enabled', 'uan', 'esic_no', 'pan', 'bank_name', 'bank_account', 'bank_ifsc',
        'attendance_required',
    ];

    protected $casts = [
        'date_joined' => 'date',
        'date_left' => 'date',
        'monthly_salary' => 'decimal:2',
        'basic_salary' => 'decimal:2',
        'ot_hourly_rate' => 'decimal:2',
        'offday_work_amount' => 'decimal:2',
        'weekly_off_day' => 'integer',
        'pt_enabled' => 'boolean',
        'pf_enabled' => 'boolean',
        'esi_enabled' => 'boolean',
        'attendance_required' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * The shift that applies: the employee's own, else the first active one.
     */
    public function effectiveShift(): ?Shift
    {
        return $this->shift ?? Shift::defaultShift();
    }

    public function weeklyOff(): int
    {
        return $this->weekly_off_day ?? (int) ($this->branch?->weekly_off_day ?? 0);
    }

    public function otRate(): float
    {
        return (float) ($this->ot_hourly_rate ?? WorkshopSettings::get('hr.default_ot_rate'));
    }

    public function offdayAmount(): float
    {
        return (float) ($this->offday_work_amount ?? WorkshopSettings::get('hr.default_offday_amount'));
    }

    /**
     * PF wage: the basic salary if set, else the whole salary.
     */
    public function pfWage(): float
    {
        return (float) ($this->basic_salary ?? $this->monthly_salary);
    }
}
