<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BranchScopedModel;
use App\Modules\Workshop\Enums\JobCardStatus;

class Bay extends BranchScopedModel
{
    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Job cards currently in this bay.
     */
    public function currentJobCards()
    {
        return $this->hasMany(JobCard::class)
            ->whereNotIn('status', JobCardStatus::terminal());
    }

    /**
     * All job cards that have used this bay.
     */
    public function jobCards()
    {
        return $this->hasMany(JobCard::class);
    }

    /**
     * Check if bay is currently occupied.
     */
    public function isOccupied(): bool
    {
        return $this->currentJobCards()->exists();
    }

    /**
     * Get the current job card in this bay.
     */
    public function getCurrentJobCard(): ?JobCard
    {
        return $this->currentJobCards()->first();
    }
}
