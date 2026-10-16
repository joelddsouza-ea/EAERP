<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One way a receipt was paid (a split payment has several: cash + UPI + card...).
 */
class PaymentPart extends Model
{
    protected $fillable = ['payment_id', 'method', 'amount', 'reference_number', 'cheque_number', 'bank_name'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
