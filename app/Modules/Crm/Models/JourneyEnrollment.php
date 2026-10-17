<?php

namespace App\Modules\Crm\Models;

use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * A lead, or a customer's car, going through a journey.
 */
class JourneyEnrollment extends Model
{
    public const ACTIVE = 'active';
    public const DONE = 'done';
    public const STOPPED = 'stopped';

    protected $fillable = ['journey_id', 'lead_id', 'customer_id', 'vehicle_id', 'job_card_id', 'anchor_date', 'next_step', 'next_run_at', 'status', 'stop_reason'];

    protected $casts = [
        'anchor_date' => 'date',
        'next_step' => 'integer',
        'next_run_at' => 'datetime',
    ];

    public function journey()
    {
        return $this->belongsTo(Journey::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }
}
