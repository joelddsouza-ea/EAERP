<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * A km reading of a car: logged by a driver or owner in the app, or taken from a job card.
 */
class OdometerReading extends Model
{
    public const SOURCES = ['driver', 'owner', 'job_card', 'staff'];

    protected $table = 'vehicle_odometer_readings';

    protected $fillable = ['vehicle_id', 'km', 'source', 'fleet_driver_id', 'job_card_id', 'user_id', 'photo_disk', 'photo_path', 'read_at'];

    protected $casts = ['km' => 'integer', 'read_at' => 'datetime'];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(FleetDriver::class, 'fleet_driver_id');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? URL::temporarySignedRoute('fleet.odometer-photo', now()->addMinutes(30), ['reading' => $this->id]) : null;
    }

    public static function latestFor(int $vehicleId): ?self
    {
        return static::where('vehicle_id', $vehicleId)->orderByDesc('read_at')->orderByDesc('id')->first();
    }
}
