<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What an employee's day adds up to, rebuilt from their punches, leave and the calendar.
 */
class AttendanceDay extends Model
{
    public const PRESENT = 'present';
    public const HALF_DAY = 'half_day';
    public const ABSENT = 'absent';
    public const WEEKLY_OFF = 'weekly_off';
    public const HOLIDAY = 'holiday';
    public const LEAVE_PAID = 'leave_paid';
    public const LEAVE_UNPAID = 'leave_unpaid';
    // Punched in but not out: a manager fixes it before payroll is finalised
    public const INCOMPLETE = 'incomplete';

    public const LABELS = [
        self::PRESENT => 'Present',
        self::HALF_DAY => 'Half day',
        self::ABSENT => 'Absent',
        self::WEEKLY_OFF => 'Weekly off',
        self::HOLIDAY => 'Holiday',
        self::LEAVE_PAID => 'Paid leave',
        self::LEAVE_UNPAID => 'Leave without pay',
        self::INCOMPLETE => 'Out-punch missing',
    ];

    protected $fillable = [
        'user_id', 'branch_id', 'date', 'first_in', 'last_out', 'worked_minutes', 'late_minutes', 'ot_minutes',
        'status', 'worked_off_day', 'leave_request_id', 'regularised', 'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'first_in' => 'datetime',
        'last_out' => 'datetime',
        'worked_minutes' => 'integer',
        'late_minutes' => 'integer',
        'ot_minutes' => 'integer',
        'worked_off_day' => 'boolean',
        'regularised' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
