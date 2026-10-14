<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BaseModel;

class TaxRate extends BaseModel
{
    protected $fillable = [
        'name',
        'code',
        'rate',
        'is_gst',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_gst' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get default GST rate (18%).
     */
    public static function defaultGstRate(): float
    {
        return static::where('code', 'GST18')
            ->where('is_active', true)
            ->first()?->rate ?? 18.00;
    }

    /**
     * Calculate CGST/SGST split.
     */
    public static function splitGst(float $taxAmount): array
    {
        $half = round($taxAmount / 2, 2);
        return [
            'cgst' => $half,
            'sgst' => $taxAmount - $half, // Handle rounding
        ];
    }
}
