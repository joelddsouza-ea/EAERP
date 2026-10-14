<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class VehicleMake extends BaseModel
{
    protected $fillable = [
        'name',
        'logo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Models for this make.
     */
    public function models()
    {
        return $this->hasMany(VehicleModel::class, 'make_id');
    }

    /**
     * Vehicles of this make.
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'make_id');
    }
}
