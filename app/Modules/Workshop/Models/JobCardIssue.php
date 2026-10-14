<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer-reported issue ("Additional Jobs" tab of the job sheet).
 */
class JobCardIssue extends Model
{
    public const PRIORITIES = ['critical', 'high', 'normal'];
    public const STATUSES = ['open', 'in_progress', 'resolved', 'deferred'];

    protected $fillable = [
        'job_card_id',
        'description',
        'priority',
        'status',
        'resolution_notes',
        'reported_by',
        'resolved_by',
        'resolved_at',
        'sort_order',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function additionalJobs()
    {
        return $this->hasMany(JobCardAdditionalJob::class);
    }

    public function media()
    {
        return $this->morphMany(JobCardMedia::class, 'attachable')->orderBy('id');
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
