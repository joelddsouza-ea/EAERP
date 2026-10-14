<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class QcSignoff extends Model
{
    protected $fillable = [
        'job_card_id',
        'phase',
        'inspector_id',
        'passed',
        'remarks',
        'failed_items',
        'inspected_at',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'failed_items' => 'array',
        'inspected_at' => 'datetime',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Inspector.
     */
    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
