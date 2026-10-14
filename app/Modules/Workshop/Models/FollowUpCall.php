<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class FollowUpCall extends Model
{
    protected $fillable = [
        'job_card_id',
        'customer_id',
        'call_type',
        'status',
        'scheduled_date',
        'scheduled_time',
        'called_at',
        'called_by',
        'call_duration',
        'rating',
        'feedback',
        'notes',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'called_at' => 'datetime',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Customer.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Called by user.
     */
    public function calledBy()
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    /**
     * Scope to pending calls.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'scheduled');
    }

    /**
     * Scope to today's calls.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_date', today());
    }

    /**
     * Scope to overdue calls.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
            ->whereDate('scheduled_date', '<', today());
    }
}
