<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A part needed on a job. Pricing loop: the advisor asks for a price (requested),
 * the spare parts coordinator records supplier quotes and picks one (quoted), the
 * customer's decision on the estimate line sets approved / declined, then the
 * coordinator orders it; receiving comes with the Phase 3 GRN.
 */
class SparePartRequest extends Model
{
    /** Technician is still listing it on the job sheet */
    public const DRAFT = 'draft';
    /** Technician sent it to the advisor ("Submit to SA for Parts"); the advisor forwards it */
    public const PROPOSED = 'proposed';
    public const REQUESTED = 'requested';
    public const QUOTED = 'quoted';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const ORDERED = 'ordered';
    public const ISSUED = 'issued';
    public const RECEIVED = 'received';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';

    /** Statuses the coordinator can still quote in */
    public const QUOTABLE = [self::REQUESTED, self::QUOTED];

    protected $fillable = [
        'job_card_id',
        'estimate_line_key',
        'requested_note',
        'quoted_by',
        'quoted_at',
        'selected_quote_id',
        'ordered_by',
        'ordered_at',
        'job_card_additional_job_id',
        'job_card_issue_id',
        'forwarded_by',
        'forwarded_at',
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
        'quoted_at' => 'datetime',
        'forwarded_at' => 'datetime',
        'ordered_at' => 'datetime',
    ];

    /**
     * Its lines on posted GRNs, newest first (with the purchases module on, a part
     * arrives through a GRN and the technician verifies it).
     */
    public function grnLines()
    {
        return $this->hasMany(GoodsReceiptLine::class)
            ->whereHas('receipt', fn ($q) => $q->where('status', GoodsReceipt::POSTED))
            ->orderByDesc('id');
    }

    /** The GRN line the part arrived on, if any (needs grnLines loaded) */
    public function arrivalLine(): ?GoodsReceiptLine
    {
        return $this->grnLines->first(fn (GoodsReceiptLine $l) => !$l->isReturned());
    }

    public function quotes()
    {
        return $this->hasMany(SparePartQuote::class);
    }

    public function selectedQuote()
    {
        return $this->belongsTo(SparePartQuote::class, 'selected_quote_id');
    }

    public function quotedBy()
    {
        return $this->belongsTo(User::class, 'quoted_by');
    }

    public function issue()
    {
        return $this->belongsTo(JobCardIssue::class, 'job_card_issue_id');
    }

    public function additionalJob()
    {
        return $this->belongsTo(JobCardAdditionalJob::class, 'job_card_additional_job_id');
    }

    public function forwardedBy()
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    public function orderedBy()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

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
