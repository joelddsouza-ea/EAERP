<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An item listed inside a plan on the estimate (oil filter, car wash, ...).
 * The oil line and the labour balance are computed, so they are not items.
 */
class PackageLineItem extends Model
{
    protected $fillable = [
        'package_id',
        'description',
        'sort_order',
        'is_active',
        'customized_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'customized_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function prices()
    {
        return $this->hasMany(PackageLineItemPrice::class, 'line_item_id');
    }
}
