<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A car sent to an outside partner for one job (washing, wheel alignment & balancing):
 * sent → done (the partner, with photos/videos) → returned (the car is back), or cancelled.
 * Until it is back the technician can't submit the job.
 */
class JobOutsideWork extends Model
{
    public const SENT = 'sent';
    public const DONE = 'done';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';

    /** Still away (or back but not yet confirmed by the workshop) */
    public const OPEN = [self::SENT, self::DONE];

    protected $fillable = [
        'job_card_id', 'kind', 'service_partner_id', 'status', 'note', 'sent_by', 'sent_at',
        'done_at', 'partner_note', 'returned_by', 'returned_at', 'cancelled_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'done_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function partner()
    {
        return $this->belongsTo(ServicePartner::class, 'service_partner_id')->withTrashed();
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function returnedBy()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function media()
    {
        return $this->morphMany(JobCardMedia::class, 'attachable')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function kindLabel(): string
    {
        return ServicePartner::KINDS[$this->kind] ?? $this->kind;
    }
}
