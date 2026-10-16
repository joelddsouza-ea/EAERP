<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer credit ledger: + money paid beyond a bill and kept for the customer,
 * − credit used to pay a job. The balance is the sum.
 */
class CustomerCredit extends Model
{
    protected $fillable = ['branch_id', 'customer_id', 'job_card_id', 'payment_id', 'amount', 'note', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function balanceFor(int $customerId): float
    {
        return round((float) static::where('customer_id', $customerId)->sum('amount'), 2);
    }
}
