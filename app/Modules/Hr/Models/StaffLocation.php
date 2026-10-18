<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One position the employee's phone sent while they were punched in.
 */
class StaffLocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'branch_id', 'work_date', 'recorded_at', 'received_at', 'lat', 'lng', 'accuracy_m', 'speed_mps',
        'heading', 'battery_pct', 'is_charging', 'source', 'job_trip_id',
    ];

    protected $casts = [
        'work_date' => 'date',
        'recorded_at' => 'datetime',
        'received_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'accuracy_m' => 'float',
        'speed_mps' => 'float',
        'heading' => 'integer',
        'battery_pct' => 'integer',
        'is_charging' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
