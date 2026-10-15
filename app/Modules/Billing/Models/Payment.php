<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;

/**
 * Money received. Job payments (advance, during work, final) are taken against the
 * job card and count towards its bill; invoice_id is the bill current at the time.
 */
class Payment extends BranchScopedModel
{
    public const STAGE_ADVANCE = 'advance';
    public const STAGE_PART = 'part';
    public const STAGE_FINAL = 'final';

    public const METHODS = ['cash', 'card', 'upi', 'bank_transfer', 'cheque', 'wallet'];

    protected $fillable = [
        'invoice_id',
        'job_card_id',
        'customer_id',
        'branch_id',
        'payment_number',
        'amount',
        'stage',
        'method',
        'reference_number',
        'payment_date',
        'notes',
        'cheque_number',
        'cheque_date',
        'bank_name',
        'status',
        'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'cheque_date' => 'date',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public static function generatePaymentNumber(int $branchId): string
    {
        $prefix = 'PAY';
        $date = now()->format('ymd');
        $branchCode = str_pad($branchId, 2, '0', STR_PAD_LEFT);

        $lastPayment = static::withoutGlobalScopes()
            ->withTrashed()
            ->where('payment_number', 'like', "{$prefix}{$date}{$branchCode}%")
            ->orderBy('payment_number', 'desc')
            ->first();

        $seq = $lastPayment ? (int) substr($lastPayment->payment_number, -4) + 1 : 1;

        return $prefix . $date . $branchCode . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        parent::booted();

        $refresh = function (Payment $payment) {
            $payment->invoice()->withoutGlobalScopes()->first()?->updatePaymentStatus();
        };

        static::saved($refresh);
        static::deleted($refresh);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('payment_date', [$from, $to]);
    }

    public function scopeMethod($query, string $method)
    {
        return $query->where('method', $method);
    }
}
