<?php

namespace App\Modules\Fleet\Models;

use App\Core\Models\User;
use App\Modules\Crm\Models\Booking;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * A problem a driver (or the fleet owner) reported on a cab from the app. Staff book it in,
 * send a pickup, or close it with a note.
 */
class FleetReport extends Model
{
    public const KINDS = [
        'breakdown' => 'Breakdown (car not moving)',
        'warning_light' => 'Warning light on',
        'noise' => 'Noise / vibration',
        'accident' => 'Accident damage',
        'tyre' => 'Tyre / puncture',
        'ac' => 'AC not cooling',
        'other' => 'Other problem',
    ];

    public const OPEN = 'open';
    public const BOOKED = 'booked';
    public const JOB_OPENED = 'job_opened';
    public const CLOSED = 'closed';

    public const STATUS_LABELS = [
        self::OPEN => 'Reported',
        self::BOOKED => 'Booked in',
        self::JOB_OPENED => 'At the workshop',
        self::CLOSED => 'Closed',
    ];

    protected $fillable = [
        'number', 'customer_id', 'vehicle_id', 'fleet_driver_id', 'branch_id', 'kind', 'description', 'odometer_km',
        'lat', 'lng', 'location_text', 'status', 'booking_id', 'job_card_id', 'handled_by', 'handled_at', 'staff_note',
    ];

    protected $casts = [
        'odometer_km' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
        'handled_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(FleetDriver::class, 'fleet_driver_id');
    }

    public function media()
    {
        return $this->hasMany(FleetReportMedia::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public static function nextNumber(): string
    {
        $prefix = 'FR' . now()->format('ym');
        $last = static::where('number', 'like', "{$prefix}%")->orderByDesc('number')->value('number');

        return $prefix . str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }
}
