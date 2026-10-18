<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Workshop\Models\JobTrip;
use Illuminate\Database\Eloquent\Model;

/**
 * The employee's latest position and whether their phone is sharing it (one row each).
 */
class StaffLiveStatus extends Model
{
    public const ACTIVE = 'active';
    public const STOPPED = 'stopped';

    // Why the phone isn't sending (reported by the app)
    public const GAPS = ['location_off', 'permission_denied', 'app_closed'];

    protected $table = 'staff_live_status';

    protected $fillable = [
        'user_id', 'branch_id', 'lat', 'lng', 'accuracy_m', 'speed_mps', 'recorded_at', 'battery_pct', 'source',
        'state', 'gap_reason', 'gap_at', 'started_at', 'stopped_at', 'job_trip_id',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'accuracy_m' => 'float',
        'speed_mps' => 'float',
        'recorded_at' => 'datetime',
        'battery_pct' => 'integer',
        'gap_at' => 'datetime',
        'started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function trip()
    {
        return $this->belongsTo(JobTrip::class, 'job_trip_id');
    }
}
