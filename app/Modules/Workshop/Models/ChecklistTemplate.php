<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class ChecklistTemplate extends BaseModel
{
    protected $fillable = [
        'package_id',
        'name',
        'slug',
        'type',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Service package.
     */
    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    /**
     * Sections in this template.
     */
    public function sections()
    {
        return $this->hasMany(ChecklistSection::class, 'template_id')
            ->orderBy('sort_order');
    }

    /**
     * All items through sections.
     */
    public function items()
    {
        return $this->hasManyThrough(
            ChecklistItem::class,
            ChecklistSection::class,
            'template_id',
            'section_id'
        );
    }
}
