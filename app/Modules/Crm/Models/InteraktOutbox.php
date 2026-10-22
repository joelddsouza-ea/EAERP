<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A contact update or an event waiting to go to Interakt's Track APIs.
 */
class InteraktOutbox extends Model
{
    public const USER = 'user';
    public const EVENT = 'event';

    protected $table = 'interakt_outbox';

    protected $fillable = ['kind', 'subject', 'event', 'payload', 'attempts', 'next_try_at', 'sent_at', 'error'];

    protected $casts = ['payload' => 'array', 'next_try_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];
}
