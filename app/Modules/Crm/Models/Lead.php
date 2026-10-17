<?php

namespace App\Modules\Crm\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Models\VehicleCategory;
use App\Modules\Workshop\Models\VehicleMake;
use App\Modules\Workshop\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone who may book: an enquiry from an ad, a call or a referral, or an existing
 * customer due for service / with pending work / gone quiet.
 */
class Lead extends Model
{
    public const NEW = 'new';
    public const CONTACTED = 'contacted';
    public const INTERESTED = 'interested';
    public const BOOKED = 'booked';
    public const WON = 'won';
    public const LOST = 'lost';
    public const UNREACHABLE = 'unreachable';

    /** Still being worked */
    public const OPEN = [self::NEW, self::CONTACTED, self::INTERESTED, self::BOOKED, self::UNREACHABLE];

    public const STATUSES = [
        self::NEW => 'New',
        self::CONTACTED => 'Contacted',
        self::INTERESTED => 'Interested',
        self::BOOKED => 'Booked',
        self::WON => 'Won',
        self::LOST => 'Lost',
        self::UNREACHABLE => 'Not reachable',
    ];

    public const KINDS = [
        'new_enquiry' => 'New enquiry',
        'service_due' => 'Service due',
        'pending_work' => 'Pending work',
        'win_back' => 'Win back',
        'insurance_renewal' => 'Insurance renewal',
        'feedback' => 'Unhappy customer',
    ];

    public const INTERESTS = [
        'scheduled' => 'Service plan',
        'general_repair' => 'General repair',
        'ac_service' => 'AC service',
        'body_shop' => 'Body shop / accident',
        'electrical' => 'Electrical',
        'detailing' => 'Detailing',
        'home_service' => 'Home service',
        'insurance' => 'Insurance',
    ];

    protected $fillable = [
        'branch_id', 'name', 'phone', 'alt_phone', 'email', 'city', 'lead_source_id', 'campaign_id', 'tracking',
        'referred_by_customer_id', 'customer_id', 'vehicle_id', 'car_text', 'registration_no', 'make_id', 'model_id',
        'category_id', 'fuel_type', 'interest', 'package_id', 'requirement', 'kind', 'status', 'temperature', 'score',
        'assigned_to', 'assigned_at', 'first_contact_due_at', 'first_contacted_at', 'sla_alerted_at', 'sla_escalated_at',
        'next_follow_up_at', 'unanswered_calls', 'last_activity_at', 'lost_reason_id', 'lost_note', 'closed_at',
        'won_revenue', 'won_job_card_id', 'opted_out_at', 'created_by',
    ];

    protected $casts = [
        'tracking' => 'array',
        'score' => 'integer',
        'unanswered_calls' => 'integer',
        'assigned_at' => 'datetime',
        'first_contact_due_at' => 'datetime',
        'first_contacted_at' => 'datetime',
        'sla_alerted_at' => 'datetime',
        'sla_escalated_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
        'opted_out_at' => 'datetime',
        'won_revenue' => 'float',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function referrer()
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    public function make()
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model()
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function lostReason()
    {
        return $this->belongsTo(LostReason::class);
    }

    public function activities()
    {
        return $this->hasMany(LeadActivity::class)->latest('id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    /**
     * "Swift (MH04AB1234)" for messages.
     */
    public function carLabel(): string
    {
        $this->loadMissing(['vehicle.make', 'vehicle.model', 'make', 'model']);
        $vehicleName = $this->vehicle ? trim(($this->vehicle->make?->name ?? '') . ' ' . ($this->vehicle->model?->name ?? '')) : '';
        // A car created from the lead may have no make / model yet: what the lead said still names it
        $car = $vehicleName ?: ($this->car_text ?: trim(($this->make?->name ?? '') . ' ' . ($this->model?->name ?? '')));
        $reg = $this->vehicle?->registration_no ?? $this->registration_no;

        return trim(($car ?: 'car') . ($reg ? " ({$reg})" : ''));
    }
}
