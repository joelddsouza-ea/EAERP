<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Engine oil quantity used by a plan for a car type.
 */
class PackageOilLitre extends Model
{
    protected $fillable = [
        'package_id',
        'category_id',
        'litres',
        'customized_at',
    ];

    protected $casts = [
        'litres' => 'decimal:2',
        'customized_at' => 'datetime',
    ];

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }
}
