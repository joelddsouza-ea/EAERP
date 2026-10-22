<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every call Interakt made to our webhook, with what we did with it.
 */
class InteraktWebhookEvent extends Model
{
    protected $fillable = ['type', 'result', 'error', 'payload', 'processed_at'];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}
