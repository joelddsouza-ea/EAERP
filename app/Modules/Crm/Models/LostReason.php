<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class LostReason extends Model
{
    protected $fillable = ['name', 'nurture', 'is_active', 'sort_order'];

    protected $casts = [
        'nurture' => 'boolean',
        'is_active' => 'boolean',
    ];
}
