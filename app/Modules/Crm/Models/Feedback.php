<?php

namespace App\Modules\Crm\Models;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = 'feedback';

    protected $fillable = ['job_card_id', 'customer_id', 'branch_id', 'rating', 'comments', 'review_clicked_at', 'resolved_at', 'resolved_by', 'resolution_note'];

    protected $casts = [
        'rating' => 'integer',
        'review_clicked_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
