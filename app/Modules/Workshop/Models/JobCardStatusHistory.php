<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobCardStatusHistory extends Model
{
    protected $table = 'job_card_status_history';

    protected $fillable = [
        'job_card_id',
        'from_status',
        'to_status',
        'notes',
        'meta',
        'changed_by',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Changed by user.
     */
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
