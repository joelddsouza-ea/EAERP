<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A place the customer's car is picked up from, dropped at or serviced at home.
 */
class CustomerAddress extends Model
{
    protected $fillable = ['customer_id', 'label', 'address', 'landmark', 'maps_url', 'lat', 'lng', 'is_default'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'is_default' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
