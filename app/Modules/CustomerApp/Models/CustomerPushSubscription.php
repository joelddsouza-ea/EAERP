<?php

namespace App\Modules\CustomerApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A browser / installed web app that receives the customer's notifications (Web Push).
 */
class CustomerPushSubscription extends Model
{
    protected $fillable = ['customer_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth', 'device', 'last_error_at'];

    protected $casts = ['last_error_at' => 'datetime'];
}
