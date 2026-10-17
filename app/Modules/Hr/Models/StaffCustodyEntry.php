<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer money an employee holds: + collected from a customer, − handed over, spent on
 * the workshop, or recovered from salary.
 */
class StaffCustodyEntry extends Model
{
    public const COLLECTED = 'collected';
    public const HANDED_OVER = 'handed_over';
    public const SPENT = 'spent';
    public const SALARY_RECOVERY = 'salary_recovery';

    protected $fillable = [
        'user_id', 'branch_id', 'entry_date', 'amount', 'kind', 'payment_part_id', 'expense_id', 'payslip_id', 'method',
        'reference_number', 'note', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function balanceFor(int $userId, ?string $upTo = null): float
    {
        return round((float) static::where('user_id', $userId)
            ->when($upTo, fn ($q) => $q->where('entry_date', '<=', $upTo))
            ->sum('amount'), 2);
    }
}
