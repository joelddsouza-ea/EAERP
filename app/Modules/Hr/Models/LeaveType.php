<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = ['name', 'code', 'is_paid', 'annual_quota', 'carry_forward', 'max_carry', 'is_active', 'sort_order'];

    protected $casts = [
        'is_paid' => 'boolean',
        'annual_quota' => 'float',
        'carry_forward' => 'boolean',
        'max_carry' => 'float',
        'is_active' => 'boolean',
    ];
}
