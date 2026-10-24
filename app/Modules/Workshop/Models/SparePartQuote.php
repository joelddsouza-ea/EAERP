<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A supplier's price for a requested part. landed_unit_cost is the GST-inclusive
 * cost per unit the workshop pays, which the advisor's margin is added to.
 */
class SparePartQuote extends Model
{
    public const GST_INCLUDED = 'incl';
    public const GST_EXCLUDED = 'excl';
    public const CASH = 'cash';
    public const GST_MODES = [self::GST_INCLUDED, self::GST_EXCLUDED, self::CASH];

    protected $fillable = [
        'spare_part_request_id',
        'supplier_id',
        // From our own stock (no supplier): priced at the average cost, issued on approval
        'stock_item_id',
        'unit_cost',
        'gst_mode',
        'gst_rate',
        'landed_unit_cost',
        'availability',
        'created_by',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'landed_unit_cost' => 'decimal:2',
    ];

    public function request()
    {
        return $this->belongsTo(SparePartRequest::class, 'spare_part_request_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * GST-inclusive cost per unit: a price quoted without GST gets GST added;
     * GST-inclusive and cash (no GST bill) prices are taken as they are.
     */
    public static function landedCost(float $unitCost, string $gstMode, float $gstRate): float
    {
        return $gstMode === self::GST_EXCLUDED
            ? round($unitCost * (1 + $gstRate / 100), 2)
            : round($unitCost, 2);
    }
}
