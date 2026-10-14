<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A job sheet instantiated for a job card from a template.
 */
class JobCardChecklist extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'job_card_id',
        'template_id',
        'sheet_kind',
        'name',
        'completed_by',
        'status',
        'started_at',
        'completed_at',
        'reopened_at',
        'reopened_by',
        'reopen_notes',
        'override_meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'override_meta' => 'array',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function items()
    {
        return $this->hasMany(JobCardChecklistItem::class, 'job_card_checklist_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isWorkSheet(): bool
    {
        return in_array($this->sheet_kind, ChecklistTemplate::WORK_SHEET_KINDS, true);
    }

    /**
     * How this sheet is judged complete (see JobSheetRuleEngine).
     */
    public function completionRule(): string
    {
        return match ($this->sheet_kind) {
            ChecklistTemplate::KIND_PRE_DELIVERY => \App\Modules\Workshop\Services\JobSheetRuleEngine::COMPLETION_ALL_ANSWERED,
            ChecklistTemplate::KIND_OBSERVATION_REPORT => \App\Modules\Workshop\Services\JobSheetRuleEngine::COMPLETION_OPTIONAL,
            default => \App\Modules\Workshop\Services\JobSheetRuleEngine::COMPLETION_ALL_RESOLVED,
        };
    }
}
