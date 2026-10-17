<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One month's payroll (for a branch, or every branch).
 */
class PayrollRun extends Model
{
    public const DRAFT = 'draft';
    public const FINALISED = 'finalised';
    public const PAID = 'paid';

    protected $fillable = [
        'branch_id', 'period', 'status', 'gross', 'deductions', 'net', 'employer_cost', 'created_by', 'calculated_at',
        'finalised_by', 'finalised_at',
    ];

    protected $casts = [
        'gross' => 'float',
        'deductions' => 'float',
        'net' => 'float',
        'employer_cost' => 'float',
        'calculated_at' => 'datetime',
        'finalised_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function finalisedBy()
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }

    public function periodStart(): string
    {
        return $this->period . '-01';
    }

    public function periodEnd(): string
    {
        return Carbon::parse($this->periodStart())->endOfMonth()->toDateString();
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
