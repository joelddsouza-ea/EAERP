<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service on the labour charges schedule (additional jobs / general repairs).
 * Rates are GST-inclusive and set per car type and fuel group.
 */
class LabourService extends Model
{
    use SoftDeletes;

    public const UNIT_JOB = 'job';
    public const UNIT_HOUR = 'hour';

    protected $fillable = [
        'name',
        'unit',
        'gst_rate',
        'sort_order',
        'is_active',
        'customized_at',
    ];

    protected $casts = [
        'gst_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'customized_at' => 'datetime',
    ];

    public function rates()
    {
        return $this->hasMany(LabourRate::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
