<?php

namespace App\Modules\Crm\Models;

use App\Modules\Workshop\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * A friend referred by a customer; rewarded (customer credit) when their first job is delivered.
 */
class Referral extends Model
{
    public const PENDING = 'pending';
    public const REWARDED = 'rewarded';

    protected $fillable = ['referrer_customer_id', 'lead_id', 'referred_customer_id', 'job_card_id', 'status', 'reward_amount', 'customer_credit_id', 'rewarded_at'];

    protected $casts = [
        'reward_amount' => 'float',
        'rewarded_at' => 'datetime',
    ];

    public function referrer()
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
