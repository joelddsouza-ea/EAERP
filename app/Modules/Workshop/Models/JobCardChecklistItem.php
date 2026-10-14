<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobCardChecklistItem extends Model
{
    protected $fillable = [
        'job_card_checklist_id',
        'checklist_item_id',
        'is_completed',
        'value',
        'remarks',
        'photo_path',
        'completed_by',
        'completed_at',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    /**
     * Job card checklist.
     */
    public function jobCardChecklist()
    {
        return $this->belongsTo(JobCardChecklist::class, 'job_card_checklist_id');
    }

    /**
     * Checklist item template.
     */
    public function checklistItem()
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    /**
     * Completed by user.
     */
    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
