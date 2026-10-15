<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * "Observations & Recommendations" on a job sheet. Rows with source = activity are
 * raised automatically when a technician flags an activity as needing attention.
 */
class JobCardObservation extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_ACTIVITY = 'activity';
    public const SOURCE_PRE_DELIVERY = 'pre_delivery';

    public const SEVERITIES = ['minor', 'medium', 'major'];

    protected $fillable = [
        'job_card_id',
        'job_card_checklist_id',
        'source',
        'source_item_id',
        'text',
        'severity',
        'sort_order',
        'customer_visible',
        'created_by',
    ];

    protected $casts = [
        'customer_visible' => 'boolean',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function sourceItem()
    {
        return $this->belongsTo(JobCardChecklistItem::class, 'source_item_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
