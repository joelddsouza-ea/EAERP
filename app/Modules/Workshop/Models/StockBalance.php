<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stock on hand of one item at one branch, with its weighted-average cost.
 */
class StockBalance extends Model
{
    protected $fillable = ['branch_id', 'stock_item_id', 'quantity', 'avg_cost'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'avg_cost' => 'decimal:4',
    ];

    public function item()
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
