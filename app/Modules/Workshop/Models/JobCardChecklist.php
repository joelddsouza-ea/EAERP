<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobCardChecklist extends Model
{
    protected $fillable = [
        'job_card_id',
        'template_id',
        'completed_by',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Template.
     */
    public function template()
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }

    /**
     * Completed by user.
     */
    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Checklist items.
     */
    public function items()
    {
        return $this->hasMany(JobCardChecklistItem::class, 'job_card_checklist_id');
    }

    /**
     * Calculate completion percentage.
     */
    public function getCompletionPercentageAttribute(): int
    {
        $total = $this->items()->count();
        if ($total === 0) {
            return 0;
        }

        $completed = $this->items()->where('is_completed', true)->count();
        return (int) round(($completed / $total) * 100);
    }
}
