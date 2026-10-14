<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

/**
 * Job-sheet master: a technician job sheet, pre-delivery checklist, observation
 * report, etc. Matched to a job card by plan (packages), service type, or applies_to_all.
 */
class ChecklistTemplate extends BaseModel
{
    public const KIND_SERVICE_ACTIVITIES = 'service_activities';
    public const KIND_AC_SERVICE = 'ac_service';
    public const KIND_GENERAL_REPAIR = 'general_repair';
    public const KIND_BODY_SHOP = 'body_shop';
    public const KIND_OBSERVATION_REPORT = 'observation_report';
    public const KIND_PRE_DELIVERY = 'pre_delivery';

    /**
     * Sheets whose activities must be resolved before the trial drive.
     */
    public const WORK_SHEET_KINDS = [
        self::KIND_SERVICE_ACTIVITIES,
        self::KIND_AC_SERVICE,
        self::KIND_GENERAL_REPAIR,
        self::KIND_BODY_SHOP,
    ];

    protected $fillable = [
        'package_id',
        'name',
        'slug',
        'type',
        'sheet_kind',
        'service_type',
        'applies_to_all',
        'completion_rule',
        'filled_by',
        'has_issues_tab',
        'has_observations',
        'version',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'applies_to_all' => 'boolean',
        'has_issues_tab' => 'boolean',
        'has_observations' => 'boolean',
    ];

    /**
     * @deprecated use packages(); kept for templates created before the pivot existed
     */
    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function packages()
    {
        return $this->belongsToMany(ServicePackage::class, 'checklist_template_package', 'template_id', 'package_id');
    }

    public function sections()
    {
        return $this->hasMany(ChecklistSection::class, 'template_id')
            ->orderBy('sort_order');
    }

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
