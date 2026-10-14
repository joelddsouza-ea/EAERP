<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class Vehicle extends BaseModel
{
    protected $fillable = [
        'customer_id',
        'make_id',
        'model_id',
        'category_id',
        'registration_no',
        'vin',
        'year',
        'color',
        'fuel_type',
        'transmission',
        'engine_no',
        'chassis_no',
        'insurance_company',
        'insurance_policy_no',
        'insurance_expiry',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'insurance_expiry' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Customer who owns this vehicle.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Vehicle make.
     */
    public function make()
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    /**
     * Vehicle model.
     */
    public function model()
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /**
     * Vehicle category (for pricing).
     */
    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    /**
     * Job cards for this vehicle.
     */
    public function jobCards()
    {
        return $this->hasMany(JobCard::class);
    }

    /**
     * Get full vehicle name.
     */
    public function getFullNameAttribute(): string
    {
        $parts = [];

        if ($this->make) {
            $parts[] = $this->make->name;
        }
        if ($this->model) {
            $parts[] = $this->model->name;
        }
        if ($this->registration_no) {
            $parts[] = "({$this->registration_no})";
        }

        return implode(' ', $parts) ?: 'Unknown Vehicle';
    }

    /**
     * Scope to search vehicles across multiple fields.
     */
    public function scopeQuickSearch($query, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('registration_no', 'like', "%{$term}%")
              ->orWhere('vin', 'like', "%{$term}%")
              ->orWhereHas('make', fn($q) => $q->where('name', 'like', "%{$term}%"))
              ->orWhereHas('model', fn($q) => $q->where('name', 'like', "%{$term}%"));
        });
    }
}
