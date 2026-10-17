<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class JourneyStep extends Model
{
    public const ACTIONS = [
        'message' => 'Send a creative',
        'task' => 'Follow-up task for the executive',
        'lead' => 'Open a lead for the executive',
    ];

    protected $fillable = ['journey_id', 'day_offset', 'send_at', 'action', 'creative_id', 'task_note', 'sort_order'];

    protected $casts = [
        'day_offset' => 'integer',
        'sort_order' => 'integer',
    ];

    public function journey()
    {
        return $this->belongsTo(Journey::class);
    }

    public function creative()
    {
        return $this->belongsTo(Creative::class);
    }
}
