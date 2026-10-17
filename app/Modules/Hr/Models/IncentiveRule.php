<?php

namespace App\Modules\Hr\Models;

use App\Modules\Workshop\Models\ServicePackage;
use Illuminate\Database\Eloquent\Model;

/**
 * An automatic incentive, worked out from the month's bills.
 */
class IncentiveRule extends Model
{
    public const BASES = [
        'per_job_delivered' => 'Rs per job billed',
        'pct_labour' => '% of labour & services billed',
        'pct_parts' => '% of parts & oil billed',
        'pct_bill' => '% of the bill total',
        'per_plan_sold' => 'Rs per service plan sold',
    ];

    protected $fillable = ['name', 'roles', 'basis', 'package_id', 'value', 'min_monthly_billing', 'is_active'];

    protected $casts = [
        'roles' => 'array',
        'value' => 'float',
        'min_monthly_billing' => 'float',
        'is_active' => 'boolean',
    ];

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function isPercentage(): bool
    {
        return str_starts_with($this->basis, 'pct_');
    }
}
