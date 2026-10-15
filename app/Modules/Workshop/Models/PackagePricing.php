<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

class PackagePricing extends Model
{
    protected $table = 'package_pricing';

    protected $fillable = [
        'package_id',
        'category_id',
        'oil_type_id',
        'price',
        'customized_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'customized_at' => 'datetime',
    ];

    /**
     * Service package.
     */
    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    /**
     * Vehicle category.
     */
    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    /**
     * Oil type.
     */
    public function oilType()
    {
        return $this->belongsTo(OilType::class, 'oil_type_id');
    }
}
