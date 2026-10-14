<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;

class JobCard extends BranchScopedModel
{
    protected $fillable = [
        'branch_id',
        'job_number',
        'customer_id',
        'vehicle_id',
        'package_id',
        'oil_type_id',
        'bay_id',
        'service_advisor_id',
        'technician_id',
        'status',
        'service_type',
        'km_reading',
        'fuel_level',
        'inventory_items',
        'customer_complaints',
        'advisor_observations',
        'estimated_delivery',
        'time_in',
        'work_started_at',
        'work_completed_at',
        'qc_completed_at',
        'time_out',
        'estimated_amount',
        'package_price',
        'addons_total',
        'labor_total',
        'parts_total',
        'third_party_total',
        'discount',
        'discount_reason',
        'total_amount',
        'internal_notes',
        'customer_approved',
        'approved_at',
        'approval_signature',
        'priority',
    ];

    protected $casts = [
        'inventory_items' => 'array',
        'estimated_delivery' => 'datetime',
        'time_in' => 'datetime',
        'work_started_at' => 'datetime',
        'work_completed_at' => 'datetime',
        'qc_completed_at' => 'datetime',
        'time_out' => 'datetime',
        'approved_at' => 'datetime',
        'customer_approved' => 'boolean',
        'estimated_amount' => 'decimal:2',
        'package_price' => 'decimal:2',
        'addons_total' => 'decimal:2',
        'labor_total' => 'decimal:2',
        'parts_total' => 'decimal:2',
        'third_party_total' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Status workflow order.
     */
    public const STATUS_ORDER = [
        'draft' => 0,
        'received' => 1,
        'in_progress' => 2,
        'qc' => 3,
        'ready' => 4,
        'delivered' => 5,
        'cancelled' => -1,
    ];

    /**
     * Customer relationship.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Vehicle relationship.
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Service package.
     */
    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    /**
     * Oil type.
     */
    public function oilType()
    {
        return $this->belongsTo(OilType::class, 'oil_type_id');
    }

    /**
     * Bay assignment.
     */
    public function bay()
    {
        return $this->belongsTo(Bay::class);
    }

    /**
     * Service advisor.
     */
    public function serviceAdvisor()
    {
        return $this->belongsTo(User::class, 'service_advisor_id');
    }

    /**
     * Assigned technician.
     */
    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Job card items (labor, parts, services).
     */
    public function items()
    {
        return $this->hasMany(JobCardItem::class);
    }

    /**
     * Addons.
     */
    public function addons()
    {
        return $this->hasMany(JobCardAddon::class);
    }

    /**
     * Checklists.
     */
    public function checklists()
    {
        return $this->hasMany(JobCardChecklist::class);
    }

    /**
     * Photos.
     */
    public function photos()
    {
        return $this->hasMany(JobCardPhoto::class);
    }

    /**
     * Status history.
     */
    public function statusHistory()
    {
        return $this->hasMany(JobCardStatusHistory::class);
    }

    /**
     * Spare part requests.
     */
    public function sparePartRequests()
    {
        return $this->hasMany(SparePartRequest::class);
    }

    /**
     * QC signoffs.
     */
    public function qcSignoffs()
    {
        return $this->hasMany(QcSignoff::class);
    }

    /**
     * Follow-up calls.
     */
    public function followUpCalls()
    {
        return $this->hasMany(FollowUpCall::class);
    }

    /**
     * Invoice raised for this job card.
     */
    public function invoice()
    {
        return $this->hasOne(\App\Modules\Billing\Models\Invoice::class);
    }

    /**
     * Check if status can transition to a new status.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->status === 'cancelled') {
            return false;
        }

        $currentOrder = self::STATUS_ORDER[$this->status] ?? -1;
        $newOrder = self::STATUS_ORDER[$newStatus] ?? -1;

        // Can always go to cancelled
        if ($newStatus === 'cancelled') {
            return $this->status !== 'delivered';
        }

        // Can only move forward in workflow (or stay same)
        return $newOrder >= $currentOrder;
    }

    /**
     * Calculate totals.
     */
    public function calculateTotals(): void
    {
        $this->labor_total = $this->items()->where('type', 'labor')->sum('total');
        $this->parts_total = $this->items()->where('type', 'part')->sum('total');
        $this->third_party_total = $this->items()->where('type', 'third_party')->sum('total');
        $this->addons_total = $this->addons()->sum('final_price');

        $this->total_amount = $this->package_price
            + $this->labor_total
            + $this->parts_total
            + $this->third_party_total
            + $this->addons_total
            - $this->discount;

        $this->save();
    }

    /**
     * Generate job number.
     */
    public static function generateJobNumber(int $branchId): string
    {
        $prefix = 'JC';
        $date = now()->format('ymd');
        $branchCode = str_pad($branchId, 2, '0', STR_PAD_LEFT);

        $lastJob = static::where('job_number', 'like', "{$prefix}{$date}{$branchCode}%")
            ->orderBy('job_number', 'desc')
            ->first();

        if ($lastJob) {
            $lastSeq = (int) substr($lastJob->job_number, -4);
            $seq = str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $seq = '0001';
        }

        return "{$prefix}{$date}{$branchCode}{$seq}";
    }

    /**
     * Scope by status.
     */
    public function scopeStatus($query, string|array $status)
    {
        return $query->whereIn('status', (array) $status);
    }

    /**
     * Scope to active (not delivered/cancelled).
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['delivered', 'cancelled']);
    }

    /**
     * Scope for technician's assigned jobs.
     */
    public function scopeForTechnician($query, int $technicianId)
    {
        return $query->where('technician_id', $technicianId);
    }

    /**
     * Scope for today's jobs.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
