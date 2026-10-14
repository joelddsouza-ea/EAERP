<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One activity on a job card's job sheet. Rule columns (media_requirement,
 * na_allowed, ...) are a snapshot of the master taken when the sheet was created.
 */
class JobCardChecklistItem extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';
    public const STATUS_NOT_DONE = 'not_done';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DONE,
        self::STATUS_NOT_APPLICABLE,
        self::STATUS_NOT_DONE,
    ];

    protected $fillable = [
        'job_card_checklist_id',
        'checklist_item_id',
        'status',
        'needs_attention',
        'section_name',
        'item_name',
        'input_type',
        'media_requirement',
        'min_media_count',
        'na_allowed',
        'expected_answer',
        'is_required',
        'sort_order',
        'is_completed',
        'value',
        'value_json',
        'remarks',
        'photo_path',
        'completed_by',
        'completed_at',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'needs_attention' => 'boolean',
        'na_allowed' => 'boolean',
        'is_required' => 'boolean',
        'min_media_count' => 'integer',
        'value_json' => 'array',
        'completed_at' => 'datetime',
    ];

    public function jobCardChecklist()
    {
        return $this->belongsTo(JobCardChecklist::class, 'job_card_checklist_id');
    }

    public function checklistItem()
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function media()
    {
        return $this->morphMany(JobCardMedia::class, 'attachable')->orderBy('id');
    }

    public function observation()
    {
        return $this->hasOne(JobCardObservation::class, 'source_item_id');
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_NOT_APPLICABLE], true);
    }

    public function requiresMedia(): bool
    {
        return $this->media_requirement !== ChecklistItem::MEDIA_NONE;
    }
}
