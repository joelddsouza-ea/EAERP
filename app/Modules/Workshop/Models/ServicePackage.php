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
        'next_service_km',
        'next_service_months',
        'is_active',
        'sort_order',
        'customized_at',
    ];

    protected $casts = [
        'features' => 'array',
        'home_service_available' => 'boolean',
        'send_customer_report' => 'boolean',
        'is_active' => 'boolean',
        'next_service_km' => 'integer',
        'next_service_months' => 'integer',
        'customized_at' => 'datetime',
    ];

    /**
     * Pricing for this package across categories and oil types.
     */
    public function pricing()
    {
        return $this->hasMany(PackagePricing::class, 'package_id');
    }

    /**
     * Items shown inside the plan on an estimate.
     */
    public function lineItems()
    {
        return $this->hasMany(PackageLineItem::class, 'package_id')->orderBy('sort_order');
    }

    /**
     * Engine oil litres per car type.
     */
    public function oilLitres()
    {
        return $this->hasMany(PackageOilLitre::class, 'package_id');
    }

    /**
     * Checklist templates for this package.
     */
    public function checklistTemplates()
    {
        return $this->hasMany(ChecklistTemplate::class, 'package_id');
    }

    /**
     * Job sheets linked to this plan (checklist_template_package pivot).
     */
    public function sheetTemplates()
    {
        return $this->belongsToMany(ChecklistTemplate::class, 'checklist_template_package', 'package_id', 'template_id');
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
