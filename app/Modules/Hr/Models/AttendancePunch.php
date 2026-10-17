<?php

namespace App\Modules\Hr\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * One punch in or out: server time, where the phone was, the selfie. Manual punches
 * (a manager's regularisation) carry a reason; a wrong punch is voided, never deleted.
 */
class AttendancePunch extends Model
{
    public const IN = 'in';
    public const OUT = 'out';

    public const SOURCE_SELFIE = 'selfie';
    public const SOURCE_TRIP = 'trip';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'user_id', 'branch_id', 'work_date', 'type', 'punched_at', 'lat', 'lng', 'accuracy_m', 'distance_m',
        'selfie_disk', 'selfie_path', 'selfie_thumb_path', 'source', 'job_trip_id', 'reason', 'device', 'created_by', 'voided_at', 'voided_by',
    ];

    protected $casts = [
        'work_date' => 'date',
        'punched_at' => 'datetime',
        'lat' => 'float',
        'lng' => 'float',
        'accuracy_m' => 'float',
        'distance_m' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeValid($query)
    {
        return $query->whereNull('voided_at');
    }

    public function selfieUrl(bool $thumbnail = false): ?string
    {
        $path = $thumbnail ? ($this->selfie_thumb_path ?? $this->selfie_path) : $this->selfie_path;
        if (!$path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'hr.selfie.stream',
            now()->addMinutes((int) config('workshop.job_sheets.media_url_ttl', 30)),
            array_filter(['punch' => $this->id, 'thumb' => $thumbnail ? 1 : null])
        );
    }
}
