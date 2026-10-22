<?php

namespace App\Modules\CustomerApp\Models;

use App\Modules\Workshop\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer signed in to the app on one phone. The token is shown once and stored hashed.
 */
class CustomerSession extends Model
{
    protected $fillable = ['customer_id', 'token_hash', 'via', 'device', 'last_used_at', 'expires_at', 'revoked_at', 'fleet_driver_id'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** Set when a fleet's driver signed in (the customer is then the fleet) */
    public function driver()
    {
        return $this->belongsTo(\App\Modules\Fleet\Models\FleetDriver::class, 'fleet_driver_id');
    }
}
