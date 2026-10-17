<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One way a receipt was paid (a split payment has several: cash + UPI + card...).
 * Method "staff_collected": the customer paid an employee (their UPI, or cash in hand),
 * who holds the money until it is handed over.
 */
class PaymentPart extends Model
{
    public const STAFF_COLLECTED = 'staff_collected';

    protected $fillable = ['payment_id', 'method', 'amount', 'reference_number', 'cheque_number', 'bank_name', 'collected_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
