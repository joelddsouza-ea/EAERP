<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of the customer estimate for a job card. Only the latest sent
 * version is shown to the customer; editing a sent estimate creates a new draft.
 */
class JobCardEstimate extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'job_card_id',
        'version',
        'status',
        'package_id',
        'category_id',
        'oil_type_id',
        'package_name',
        'category_name',
        'oil_type_name',
        'package_price',
        'plan_discount_pct',
        'plan_discount_flat',
        'extras_discount_pct',
        'extras_discount_flat',
        'plan_total',
        'extras_total',
        'grand_total',
        'next_service_km',
        'next_service_date',
        'payment_mode',
        'notes',
        'created_by',
        'sent_at',
        'sent_by',
        'customer_viewed_at',
        'responded_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'package_price' => 'decimal:2',
        'plan_discount_pct' => 'decimal:2',
        'plan_discount_flat' => 'decimal:2',
        'extras_discount_pct' => 'decimal:2',
        'extras_discount_flat' => 'decimal:2',
        'plan_total' => 'decimal:2',
        'extras_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'next_service_km' => 'integer',
        'next_service_date' => 'date',
        'sent_at' => 'datetime',
        'customer_viewed_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function lines()
    {
        return $this->hasMany(JobCardEstimateLine::class, 'estimate_id')->orderBy('sort_order')->orderBy('id');
    }

    public function comments()
    {
        return $this->hasMany(JobCardEstimateComment::class, 'estimate_id')->orderBy('id');
    }

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
