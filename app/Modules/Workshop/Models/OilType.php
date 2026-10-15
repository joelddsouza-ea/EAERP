<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class OilType extends BaseModel
{
    protected $fillable = [
        'name',
        'product_name',
        'rate_per_litre',
        'slug',
        'description',
        'price_multiplier',
        'sort_order',
        'is_active',
        'customized_at',
    ];

    protected $casts = [
        'price_multiplier' => 'decimal:2',
        'rate_per_litre' => 'decimal:2',
        'customized_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Package pricing for this oil type.
     */
    public function packagePricing()
    {
        return $this->hasMany(PackagePricing::class, 'oil_type_id');
    }
}
