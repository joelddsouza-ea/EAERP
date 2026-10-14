<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;

class Payment extends BranchScopedModel
{
    protected $fillable = [
        'invoice_id',
        'branch_id',
        'payment_number',
        'amount',
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

    /**
     * Invoice.
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Received by user.
     */
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Generate payment number.
     */
    public static function generatePaymentNumber(int $branchId): string
    {
        $prefix = 'PAY';
        $date = now()->format('ymd');
        $branchCode = str_pad($branchId, 2, '0', STR_PAD_LEFT);

        $lastPayment = static::where('payment_number', 'like', "{$prefix}{$date}{$branchCode}%")
            ->orderBy('payment_number', 'desc')
            ->first();

        if ($lastPayment) {
            $lastSeq = (int) substr($lastPayment->payment_number, -4);
            $seq = str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $seq = '0001';
        }

        return "{$prefix}{$date}{$branchCode}{$seq}";
    }

    /**
     * Update invoice payment status on save.
     */
    protected static function booted(): void
    {
        static::saved(function ($payment) {
            $payment->invoice->updatePaymentStatus();
        });

        static::deleted(function ($payment) {
            $payment->invoice->updatePaymentStatus();
        });
    }

    /**
     * Scope for date range.
     */
    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('payment_date', [$from, $to]);
    }

    /**
     * Scope by method.
     */
    public function scopeMethod($query, string $method)
    {
        return $query->where('method', $method);
    }
}
