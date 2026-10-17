<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Salary paid ahead; recovered from payslips until cleared.
 */
class SalaryAdvance extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'branch_id', 'amount', 'given_on', 'method', 'reference_number', 'recover_per_month', 'recovered',
        'status', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'given_on' => 'date',
        'recover_per_month' => 'float',
        'recovered' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balance(): float
    {
        return round($this->amount - $this->recovered, 2);
    }
}
