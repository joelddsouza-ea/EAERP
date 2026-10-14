<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class VehicleModel extends BaseModel
{
    protected $table = 'vehicle_models';

    protected $fillable = [
        'make_id',
        'default_category_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Make for this model.
     */
    public function make()
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    /**
     * Default category for this model.
     */
    public function defaultCategory()
    {
        return $this->belongsTo(VehicleCategory::class, 'default_category_id');
    }

    /**
     * Vehicles of this model.
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'model_id');
    }
}
