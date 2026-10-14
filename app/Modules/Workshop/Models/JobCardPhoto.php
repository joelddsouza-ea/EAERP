<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobCardPhoto extends Model
{
    protected $fillable = [
        'job_card_id',
        'photo_type',
        'file_path',
        'thumbnail_path',
        'caption',
        'uploaded_by',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Uploaded by user.
     */
    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
