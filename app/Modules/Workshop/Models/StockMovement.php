<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One change to stock: in from a GRN, issued to / returned from a job, adjusted, or
 * reversed by a GRN cancellation. Quantity is + into stock, − out of it.
 */
class StockMovement extends Model
{
    public const IN = 'in';
    public const ISSUE = 'issue';
    public const RETURN = 'return';
    public const ADJUST = 'adjust';
    public const GRN_CANCEL = 'grn_cancel';

    protected $fillable = ['branch_id', 'stock_item_id', 'type', 'quantity', 'unit_cost', 'value', 'job_card_id', 'goods_receipt_line_id', 'note', 'created_by'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'value' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
