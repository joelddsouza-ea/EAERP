<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'branch_id', 'leave_type_id', 'from_date', 'to_date', 'half_day', 'days', 'unpaid_days', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'half_day' => 'boolean',
        'days' => 'float',
        'unpaid_days' => 'float',
        'decided_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
