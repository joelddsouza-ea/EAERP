<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * A driver of a fleet customer (cab owner). Signs in to the customer app with their own
 * mobile number and sees only the cabs linked to them.
 */
class FleetDriver extends Model
{
    public const VIA_STAFF = 'staff';
    public const VIA_OWNER_APP = 'owner_app';

    protected $fillable = ['customer_id', 'name', 'phone', 'is_active', 'notes', 'created_via', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicles()
    {
        return $this->belongsToMany(Vehicle::class, 'fleet_driver_vehicle');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function firstName(): string
    {
        return strtok($this->name, ' ') ?: $this->name;
    }
}
