<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A line on an estimate. Plan lines are included in the package price; additional
 * lines are approved or declined by the customer. line_key stays the same across
 * versions so decisions can carry over.
 */
class JobCardEstimateLine extends Model
{
    public const SECTION_PLAN = 'plan';
    public const SECTION_ADDITIONAL = 'additional';

    public const KIND_OIL = 'oil';
    public const KIND_PLAN_ITEM = 'plan_item';
    public const KIND_PLAN_EXTRA = 'plan_extra';
    public const KIND_LABOUR_BALANCE = 'labour_balance';
    public const ADDITIONAL_KINDS = ['part', 'labor', 'third_party'];

    public const INCLUDED = 'included';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';

    public const VIA_CUSTOMER = 'customer_link';
    public const VIA_ADVISOR = 'advisor_on_behalf';
    /** A fleet's driver, within the fleet's limit per job */
    public const VIA_DRIVER = 'fleet_driver';

    /** Wording the owner chose for declined additional work */
    public const DECLINED_LABEL = 'Recommended but not approved by customer';

    protected $fillable = [
        'estimate_id',
        'line_key',
        'section',
        'kind',
        'description',
        'quantity',
        'rate',
        'amount',
        'sort_order',
        'approval_status',
        'decided_via',
        'decided_by',
        'decided_by_driver_id',
        'decided_at',
        'job_card_additional_job_id',
        'job_card_issue_id',
        'labour_service_id',
        'spare_part_request_id',
        // Priced including GST (default) or excluding it (GST added on top)
        'gst_inclusive',
        'tax_rate',
        'unit_cost',
        'margin_pct',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'gst_inclusive' => 'boolean',
        'tax_rate' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'margin_pct' => 'decimal:2',
        'sort_order' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function estimate()
    {
        return $this->belongsTo(JobCardEstimate::class, 'estimate_id');
    }

    public function media()
    {
        return $this->belongsToMany(JobCardMedia::class, 'job_card_estimate_line_media', 'estimate_line_id', 'job_card_media_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function decidedByDriver()
    {
        return $this->belongsTo(\App\Modules\Fleet\Models\FleetDriver::class, 'decided_by_driver_id');
    }

    public function labourService()
    {
        return $this->belongsTo(LabourService::class);
    }

    public function sparePartRequest()
    {
        return $this->belongsTo(SparePartRequest::class);
    }

    public function additionalJob()
    {
        return $this->belongsTo(JobCardAdditionalJob::class, 'job_card_additional_job_id');
    }

    public function isAdditional(): bool
    {
        return $this->section === self::SECTION_ADDITIONAL;
    }

    /**
     * Rate per unit as the customer pays it (GST included).
     */
    public function grossRate(): float
    {
        $rate = (float) $this->rate;

        return $this->gst_inclusive === false ? round($rate * (1 + (float) $this->tax_rate / 100), 2) : $rate;
    }

    /**
     * Quantity × rate, GST included.
     */
    public function customerAmount(): float
    {
        return round((float) $this->quantity * $this->grossRate(), 2);
    }
}
