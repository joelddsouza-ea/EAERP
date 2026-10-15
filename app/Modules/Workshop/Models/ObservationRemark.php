<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Advice attached to an observation on the report (master list, editable).
 * Labels with needs_days use "_" for the number of days, e.g. "Fix within _ days".
 */
class ObservationRemark extends Model
{
    public const CUSTOMER_NOT_APPROVED = 'customer_not_approved';
    public const FIX_AT_NEXT_SERVICE = 'fix_at_next_service';

    protected $fillable = [
        'code',
        'label',
        'needs_days',
        'sort_order',
        'is_active',
        'customized_at',
    ];

    protected $casts = [
        'needs_days' => 'boolean',
        'is_active' => 'boolean',
        'customized_at' => 'datetime',
    ];

    public static function idFor(string $code): ?int
    {
        return static::where('code', $code)->value('id');
    }

    /**
     * The remark as printed, with the days filled in.
     */
    public function text(?int $days): string
    {
        return $this->needs_days ? str_replace('_', (string) ($days ?? '_'), $this->label) : $this->label;
    }
}
