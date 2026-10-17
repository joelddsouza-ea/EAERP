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
        // CRM: messages to leads, quotes, journeys, bookings, feedback (purpose "crm")
        'lead_id',
        'purpose',
        'creative_id',
        'journey_enrollment_id',
        'media_url',
        'scheduled_at',
        'assigned_to',
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
        'scheduled_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(\App\Modules\Crm\Models\Lead::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

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
