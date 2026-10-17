<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An employee's paid leave for a year: the quota allotted plus days carried forward.
 */
class LeaveBalance extends Model
{
    protected $fillable = ['user_id', 'leave_type_id', 'year', 'allotted', 'carried'];

    protected $casts = [
        'year' => 'integer',
        'allotted' => 'float',
        'carried' => 'float',
    ];

    public function type()
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
