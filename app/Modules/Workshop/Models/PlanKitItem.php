<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a service plan uses from stock for a car class (null class: every class).
 * Engine oil is not listed here: it comes from the plan's oil litres and the job's oil grade.
 */
class PlanKitItem extends Model
{
    protected $fillable = ['package_id', 'category_id', 'stock_item_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:2'];

    public function item()
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }
}
