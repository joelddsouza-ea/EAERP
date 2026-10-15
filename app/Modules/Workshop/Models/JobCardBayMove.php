<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A job moved from one bay to another (e.g. service bay → wash bay).
 */
class JobCardBayMove extends Model
{
    protected $fillable = [
        'job_card_id',
        'from_bay_id',
        'to_bay_id',
        'notes',
        'moved_by',
        'moved_at',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
    ];

    public function fromBay()
    {
        return $this->belongsTo(Bay::class, 'from_bay_id');
    }

    public function toBay()
    {
        return $this->belongsTo(Bay::class, 'to_bay_id');
    }

    public function movedBy()
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
