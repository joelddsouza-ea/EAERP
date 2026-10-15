<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Additional work done on a job card beyond the plan ("Jobs Done" on the sheet).
 */
class JobCardAdditionalJob extends Model
{
    protected $fillable = [
        'job_card_id',
        'job_card_issue_id',
        'description',
        'parts_used',
        'cost',
        'done_by',
        'job_card_item_id',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Parts the technician asked for to do this job.
     */
    public function partRequests()
    {
        return $this->hasMany(SparePartRequest::class, 'job_card_additional_job_id');
    }

    public function issue()
    {
        return $this->belongsTo(JobCardIssue::class, 'job_card_issue_id');
    }

    public function media()
    {
        return $this->morphMany(JobCardMedia::class, 'attachable')->orderBy('id');
    }

    public function doneBy()
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
