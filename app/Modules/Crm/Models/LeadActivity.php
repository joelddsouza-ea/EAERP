<?php

namespace App\Modules\Crm\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One event on a lead's timeline.
 */
class LeadActivity extends Model
{
    public const OUTCOMES = [
        'connected' => 'Spoke to them',
        'no_answer' => 'No answer',
        'busy' => 'Busy / switched off',
        'call_back' => 'Asked to call back',
        'not_interested' => 'Not interested',
        'booked' => 'Booked',
    ];

    protected $fillable = ['lead_id', 'type', 'outcome', 'body', 'meta', 'user_id'];

    protected $casts = ['meta' => 'array'];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
