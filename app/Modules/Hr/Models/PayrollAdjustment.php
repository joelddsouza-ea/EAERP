<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A manual line for one month's pay: incentive, bonus, reimbursement (earnings) or a
 * deduction / penalty.
 */
class PayrollAdjustment extends Model
{
    public const EARNINGS = ['incentive' => 'Incentive', 'bonus' => 'Bonus', 'reimbursement' => 'Reimbursement'];
    public const DEDUCTIONS = ['deduction' => 'Deduction', 'penalty' => 'Penalty'];

    protected $fillable = ['user_id', 'period', 'kind', 'amount', 'note', 'created_by'];

    protected $casts = ['amount' => 'float'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEarning(): bool
    {
        return isset(self::EARNINGS[$this->kind]);
    }
}
