<?php

namespace App\Modules\CustomerApp\Models;

use App\Core\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Database\Eloquent\Model;

/**
 * "I've paid by UPI": the customer's reference number waits for back office to see the money
 * in the bank and confirm it, which records the payment on the job.
 */
class PaymentClaim extends Model
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'job_card_id', 'customer_id', 'branch_id', 'amount', 'utr', 'screenshot_disk', 'screenshot_path',
        'status', 'payment_id', 'decided_by', 'decided_at', 'reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScopes();
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
