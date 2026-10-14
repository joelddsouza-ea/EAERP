<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class OilType extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_multiplier',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'price_multiplier' => 'decimal:2',
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
