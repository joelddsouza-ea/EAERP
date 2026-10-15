<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A message to a customer. With the WhatsApp share channel it is "prepared" and
 * an advisor sends it from the workshop phone; with the API channel it is sent
 * directly.
 */
class CustomerMessage extends Model
{
    public const PREPARED = 'prepared';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'job_card_id',
        'customer_id',
        'template',
        'body',
        'phone',
        'channel',
        'status',
        'error',
        'prepared_by',
        'sent_at',
        'sent_by',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
