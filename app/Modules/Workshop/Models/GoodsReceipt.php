<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Goods received note: one supplier bill or challan as the spare parts coordinator
 * received it — job parts, consumables into stock and third-party services.
 */
class GoodsReceipt extends Model
{
    public const POSTED = 'posted';
    public const CANCELLED = 'cancelled';

    public const BILL_GST = 'gst_invoice';
    public const BILL_CHALLAN = 'challan';
    /** Bought without a bill (cash purchase): no input GST */
    public const BILL_NONE = 'no_bill';
    public const BILL_TYPES = [self::BILL_GST, self::BILL_CHALLAN, self::BILL_NONE];

    public const CASH = 'cash';
    public const CREDIT = 'credit';

    protected $fillable = [
        'branch_id', 'grn_number', 'supplier_id', 'supplier_bill_no', 'bill_date', 'bill_type', 'payment_mode',
        'taxable_amount', 'gst_amount', 'total_amount', 'status', 'notes', 'received_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'taxable_amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('id');
    }

    public function media()
    {
        return $this->hasMany(GoodsReceiptMedia::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::POSTED);
    }

    public static function generateNumber(int $branchId): string
    {
        $prefix = 'GRN' . now()->format('ymd') . str_pad((string) $branchId, 2, '0', STR_PAD_LEFT);
        $last = static::where('grn_number', 'like', "{$prefix}%")->orderByDesc('grn_number')->value('grn_number');
        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
