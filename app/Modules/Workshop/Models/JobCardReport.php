<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The advisor's choices for the customer service report (360/ELITE) and its release.
 */
class JobCardReport extends Model
{
    protected $fillable = [
        'job_card_id',
        'include_activities',
        'include_additional',
        'include_observations',
        'advisor_notes',
        'released_at',
        'released_by',
    ];

    protected $casts = [
        'include_activities' => 'boolean',
        'include_additional' => 'boolean',
        'include_observations' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
