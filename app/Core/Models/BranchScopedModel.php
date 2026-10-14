<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Core\Models\Branch;

/**
 * Branch Scoped Model
 *
 * Extends BaseModel to add automatic branch scoping for operational data.
 * Used for models like JobCard, Invoice, etc. that belong to a specific branch.
 *
 * Customer and Vehicle models should NOT extend this - they are org-wide.
 */
abstract class BranchScopedModel extends BaseModel
{
    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        parent::booted();

        // Auto-scope queries to current branch context
        static::addGlobalScope('branch', function (Builder $builder) {
            $branchContext = app('branch.context');

            // If user is Owner (has all_branches permission), don't filter
            if ($branchContext->hasAllBranchesAccess()) {
                return;
            }

            $branchIds = $branchContext->getAccessibleBranchIds();

            if (!empty($branchIds)) {
                $builder->whereIn('branch_id', $branchIds);
            }
        });

        // Auto-set branch_id on creating
        static::creating(function ($model) {
            if (empty($model->branch_id)) {
                $branchContext = app('branch.context');
                $model->branch_id = $branchContext->getCurrentBranchId();
            }
        });
    }

    /**
     * Relationship to branch.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Scope to a specific branch.
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->withoutGlobalScope('branch')->where('branch_id', $branchId);
    }

    /**
     * Scope to multiple branches.
     */
    public function scopeForBranches(Builder $query, array $branchIds): Builder
    {
        return $query->withoutGlobalScope('branch')->whereIn('branch_id', $branchIds);
    }

    /**
     * Scope without branch filtering (for admin/owner access).
     */
    public function scopeAllBranches(Builder $query): Builder
    {
        return $query->withoutGlobalScope('branch');
    }
}
