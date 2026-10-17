<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One leg of a pickup & drop or home-service job: the driver (or home-service
 * technician) it is given to, where and when, and how far it has got.
 *
 * Pickup photos/videos are the job's intake media (attachable job_card); drop and
 * home-visit closing photos are attached to the trip (attachable job_trip).
 */
class JobTrip extends Model
{
    public const KIND_PICKUP = 'pickup';
    public const KIND_DROP = 'drop';
    public const KIND_HOME_VISIT = 'home_visit';
    public const KINDS = [self::KIND_PICKUP, self::KIND_DROP, self::KIND_HOME_VISIT];

    public const UNASSIGNED = 'unassigned';
    public const SCHEDULED = 'scheduled';
    /** On the way (drop: the car has left the workshop) */
    public const EN_ROUTE = 'en_route';
    /** Pickup: the driver has the car and is driving it to the workshop */
    public const COLLECTED = 'collected';
    /** Home visit: the technician is at the customer's place, working */
    public const ARRIVED = 'arrived';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    public const OPEN = [self::UNASSIGNED, self::SCHEDULED, self::EN_ROUTE, self::COLLECTED, self::ARRIVED];
    /** Not yet started: can be rescheduled, reassigned or cancelled */
    public const NOT_STARTED = [self::UNASSIGNED, self::SCHEDULED];

    /** Labelled photos at drop */
    public const SIDES = ['front', 'rear', 'left', 'right'];

    protected $fillable = [
        'branch_id', 'job_card_id', 'kind', 'status', 'assigned_to',
        'address', 'landmark', 'maps_url', 'lat', 'lng',
        'slot_date', 'slot_from', 'slot_to', 'charge',
        'started_at', 'arrived_at', 'completed_at', 'handover_name', 'notes',
        'created_by', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'slot_date' => 'date:Y-m-d',
        'charge' => 'decimal:2',
        'lat' => 'float',
        'lng' => 'float',
        'started_at' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function media()
    {
        return $this->morphMany(JobCardMedia::class, 'attachable')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::KIND_PICKUP => 'Pickup',
            self::KIND_DROP => 'Drop',
            default => 'Home visit',
        };
    }

    /**
     * Google Maps link to the customer: the shared pin, else coordinates, else the address.
     */
    public function navigateUrl(): ?string
    {
        if ($this->maps_url) {
            return $this->maps_url;
        }
        if ($this->lat !== null && $this->lng !== null) {
            return "https://www.google.com/maps/dir/?api=1&destination={$this->lat},{$this->lng}";
        }

        return $this->address
            ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode(trim($this->address . ' ' . $this->landmark))
            : null;
    }
}
