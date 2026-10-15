<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobCardEstimateComment extends Model
{
    public const AUTHOR_CUSTOMER = 'customer';
    public const AUTHOR_STAFF = 'staff';

    protected $fillable = [
        'estimate_id',
        'author_type',
        'user_id',
        'body',
    ];

    public function estimate()
    {
        return $this->belongsTo(JobCardEstimate::class, 'estimate_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
