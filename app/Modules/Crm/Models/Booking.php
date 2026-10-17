<?php

namespace App\Modules\Crm\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment: a car, a day and a slot, what it comes for, and how (drive-in,
 * pickup & drop, home service). It becomes a job card when the car arrives (or when a
 * pickup / home visit is confirmed).
 */
class Booking extends Model
{
    public const BOOKED = 'booked';
    public const CONFIRMED = 'confirmed';
    public const ARRIVED = 'arrived';
    public const CONVERTED = 'converted';
    public const NO_SHOW = 'no_show';
    public const CANCELLED = 'cancelled';

    /** Takes a place in its slot */
    public const HOLDING = [self::BOOKED, self::CONFIRMED, self::ARRIVED, self::CONVERTED];

    public const STATUSES = [
        self::BOOKED => 'Booked',
        self::CONFIRMED => 'Confirmed',
        self::ARRIVED => 'Arrived',
        self::CONVERTED => 'Job card opened',
        self::NO_SHOW => 'Did not come',
        self::CANCELLED => 'Cancelled',
    ];

    protected $fillable = [
        'branch_id', 'number', 'lead_id', 'customer_id', 'vehicle_id', 'booking_date', 'slot_from', 'slot_to',
        'service_type', 'package_id', 'oil_type_id', 'service_mode', 'address', 'landmark', 'maps_url', 'notes',
        'status', 'job_card_id', 'booked_via', 'confirmed_at', 'reminded_at', 'arrived_at', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'confirmed_at' => 'datetime',
        'reminded_at' => 'datetime',
        'arrived_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
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

    public function package()
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function oilType()
    {
        return $this->belongsTo(OilType::class, 'oil_type_id');
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::BOOKED, self::CONFIRMED, self::ARRIVED], true);
    }

    public static function generateNumber(): string
    {
        $prefix = 'BK-' . now()->format('ym') . '-';
        $last = static::where('number', 'like', $prefix . '%')->orderByDesc('id')->value('number');

        return $prefix . str_pad((string) ($last ? (int) substr($last, strlen($prefix)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
