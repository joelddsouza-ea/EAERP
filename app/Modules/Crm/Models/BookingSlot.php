<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bookable time of day (per weekday, per branch or every branch) and how many cars it takes.
 */
class BookingSlot extends Model
{
    protected $fillable = ['branch_id', 'weekday', 'start_time', 'end_time', 'capacity', 'is_active'];

    protected $casts = [
        'weekday' => 'integer',
        'capacity' => 'integer',
        'is_active' => 'boolean',
    ];
}
