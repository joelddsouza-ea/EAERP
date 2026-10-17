<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A paid holiday, for one branch or (branch_id null) every branch.
 */
class Holiday extends Model
{
    protected $fillable = ['branch_id', 'date', 'name'];

    protected $casts = ['date' => 'date'];

    public function scopeForBranch($query, ?int $branchId)
    {
        return $query->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)));
    }
}
