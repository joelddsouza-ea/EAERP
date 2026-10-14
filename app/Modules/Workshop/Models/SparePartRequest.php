<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class SparePartRequest extends Model
{
    protected $fillable = [
        'job_card_id',
        'part_name',
        'part_number',
        'quantity',
        'unit',
        'status',
        'remarks',
        'rejection_reason',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'issued_by',
        'issued_at',
        'issued_quantity',
        'received_by',
        'received_at',
        'received_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'issued_quantity' => 'decimal:2',
        'received_quantity' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Requested by user.
     */
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Approved by user.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Issued by user.
     */
    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Received by user.
     */
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
