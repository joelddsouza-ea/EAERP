<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Amount of a plan item for a car type; 0 means not included for that car type.
 */
class PackageLineItemPrice extends Model
{
    protected $fillable = [
        'line_item_id',
        'category_id',
        'amount',
        'customized_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'customized_at' => 'datetime',
    ];

    public function lineItem()
    {
        return $this->belongsTo(PackageLineItem::class, 'line_item_id');
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }
}
