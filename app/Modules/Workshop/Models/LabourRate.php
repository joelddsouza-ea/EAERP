<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GST-inclusive labour rate for a service, car type and fuel group.
 * A null rate means the service is not offered for that car.
 */
class LabourRate extends Model
{
    public const FUEL_PETROL = 'petrol';
    public const FUEL_DIESEL = 'diesel';
    public const FUEL_GROUPS = [self::FUEL_PETROL, self::FUEL_DIESEL];

    protected $fillable = [
        'labour_service_id',
        'category_id',
        'fuel_group',
        'rate',
        'customized_at',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'customized_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(LabourService::class, 'labour_service_id');
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    /**
     * Diesel cars use the diesel column; petrol, CNG, hybrid, electric and unknown use petrol.
     */
    public static function fuelGroupFor(?string $fuelType): string
    {
        return $fuelType === 'diesel' ? self::FUEL_DIESEL : self::FUEL_PETROL;
    }
}
