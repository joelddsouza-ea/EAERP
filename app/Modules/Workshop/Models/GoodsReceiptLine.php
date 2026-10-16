<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of a GRN: a part bought for a job (the technician verifies it), consumables
 * into stock, or a third-party service done for a job.
 */
class GoodsReceiptLine extends Model
{
    public const JOB_PART = 'job_part';
    public const STOCK = 'stock';
    public const THIRD_PARTY = 'third_party';
    public const KINDS = [self::JOB_PART, self::STOCK, self::THIRD_PARTY];

    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const WRONG_PART = 'wrong_part';

    protected $fillable = [
        'goods_receipt_id', 'kind', 'spare_part_request_id', 'job_card_id', 'stock_item_id', 'estimate_line_key',
        'description', 'part_number', 'brand', 'quantity', 'unit', 'mrp', 'unit_cost', 'gst_mode', 'gst_rate',
        'taxable_amount', 'gst_amount', 'total',
        'verification', 'verified_by', 'verified_at', 'verification_note',
        'returned_quantity', 'returned_by', 'returned_at', 'return_reason',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'mrp' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'returned_quantity' => 'decimal:2',
        'verified_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function receipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function partRequest()
    {
        return $this->belongsTo(SparePartRequest::class, 'spare_part_request_id');
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function media()
    {
        return $this->hasMany(GoodsReceiptMedia::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isReturned(): bool
    {
        return (float) $this->returned_quantity > 0;
    }

    /**
     * [taxable, gst, total] of a line from its supplier price: a price without GST gets it
     * added; a GST-inclusive price has it extracted; a cash purchase (no GST bill) has none.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function amounts(float $quantity, float $unitCost, string $gstMode, float $gstRate): array
    {
        $gross = round($quantity * $unitCost, 2);

        return match ($gstMode) {
            SparePartQuote::GST_EXCLUDED => [$gross, round($gross * $gstRate / 100, 2), round($gross * (1 + $gstRate / 100), 2)],
            SparePartQuote::GST_INCLUDED => [
                round($gross / (1 + $gstRate / 100), 2),
                round($gross - round($gross / (1 + $gstRate / 100), 2), 2),
                $gross,
            ],
            default => [$gross, 0.0, $gross],
        };
    }
}
