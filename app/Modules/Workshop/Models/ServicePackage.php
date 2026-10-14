<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class ServicePackage extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'activity_points',
        'description',
        'features',
        'home_service_available',
        'send_customer_report',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'home_service_available' => 'boolean',
        'send_customer_report' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Pricing for this package across categories and oil types.
     */
    public function pricing()
    {
        return $this->hasMany(PackagePricing::class, 'package_id');
    }

    /**
     * Checklist templates for this package.
     */
    public function checklistTemplates()
    {
        return $this->hasMany(ChecklistTemplate::class, 'package_id');
    }

    /**
     * Job cards using this package.
     */
    public function jobCards()
    {
        return $this->hasMany(JobCard::class, 'package_id');
    }

    /**
     * Get price for a specific category and oil type.
     */
    public function getPriceFor(int $categoryId, int $oilTypeId): ?float
    {
        $pricing = $this->pricing()
            ->where('category_id', $categoryId)
            ->where('oil_type_id', $oilTypeId)
            ->first();

        return $pricing?->price;
    }
}
