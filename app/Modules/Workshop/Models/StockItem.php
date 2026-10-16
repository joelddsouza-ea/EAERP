<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A consumable kept in stock (engine oil, filters, consumables, detailing supplies).
 * Parts bought for one job are not stock items: they go straight to the job on the GRN.
 */
class StockItem extends Model
{
    public const CATEGORIES = ['oil', 'filter', 'consumable', 'detailing'];
    public const UNITS = ['nos', 'ltr', 'ml', 'kg', 'gm', 'set', 'mtr'];

    protected $fillable = ['name', 'category', 'unit', 'oil_type_id', 'hsn_code', 'gst_rate', 'reorder_level', 'is_active'];

    protected $casts = [
        'gst_rate' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function oilType()
    {
        return $this->belongsTo(OilType::class);
    }

    public function balances()
    {
        return $this->hasMany(StockBalance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
