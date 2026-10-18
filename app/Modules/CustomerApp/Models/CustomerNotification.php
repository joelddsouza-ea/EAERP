<?php

namespace App\Modules\CustomerApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item in the customer's in-app inbox (also pushed to an installed web app).
 */
class CustomerNotification extends Model
{
    protected $fillable = ['customer_id', 'kind', 'title', 'body', 'link', 'dedupe_key', 'read_at', 'pushed_at'];

    protected $casts = [
        'read_at' => 'datetime',
        'pushed_at' => 'datetime',
    ];
}
