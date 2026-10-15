<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit row for an approval decision on an estimate line.
 */
class JobCardEstimateDecision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'job_card_id',
        'estimate_id',
        'line_key',
        'description',
        'from_status',
        'to_status',
        'decided_via',
        'user_id',
        'note',
        'customer_message_id',
        'ip_address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customerMessage()
    {
        return $this->belongsTo(CustomerMessage::class);
    }
}
