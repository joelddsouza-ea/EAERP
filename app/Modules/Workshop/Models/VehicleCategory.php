<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class VehicleCategory extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
        'customized_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'customized_at' => 'datetime',
    ];

    /**
     * Package pricing for this category.
     */
    public function packagePricing()
    {
        return $this->hasMany(PackagePricing::class, 'category_id');
    }

    /**
     * Vehicles in this category.
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'category_id');
    }
}
