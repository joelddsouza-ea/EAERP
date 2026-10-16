<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;

/**
 * Money on a job. Received (advance, during work, final; a receipt may be split over
 * several methods, see parts) counts towards the job's bill; invoice_id is the bill
 * current at the time. Money paid beyond the bill goes back out as a refund or into
 * the customer's credit: both are negative payments, so the job's payments always
 * add up to what the business holds for it.
 */
class Payment extends BranchScopedModel
{
    public const STAGE_ADVANCE = 'advance';
    public const STAGE_PART = 'part';
    public const STAGE_FINAL = 'final';
    /** Paid back to the customer (negative amount) */
    public const STAGE_REFUND = 'refund';
    /** Moved to the customer's credit (negative amount, method customer_credit) */
    public const STAGE_CREDIT = 'credit';

    /** Ways money is received or refunded */
    public const METHODS = ['cash', 'card', 'upi', 'bank_transfer', 'cheque', 'wallet'];
    public const METHOD_SPLIT = 'split';
    /** Paid from, or moved to, the customer's credit: not money in or out of the business */
    public const METHOD_CREDIT = 'customer_credit';

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

    public function parts()
    {
        return $this->hasMany(PaymentPart::class)->orderBy('id');
    }

    public function isRefund(): bool
    {
        return $this->stage === self::STAGE_REFUND;
    }

    /**
     * Receipts that moved money (collections, cash book): completed, and not only a move
     * into or out of the customer's credit. Add up their amounts with moneyTotal(), which
     * leaves out the credit part of a split receipt.
     */
    public function scopeMoney($query)
    {
        return $query->where('status', 'completed')->where('method', '!=', self::METHOD_CREDIT);
    }

    /**
     * Money received less refunded for the receipts of a query, from their parts
     * (credit used or kept is not money).
     */
    public static function moneySum($query): float
    {
        return round((float) PaymentPart::whereIn('payment_id', (clone $query)->money()->select('payments.id'))
            ->where('method', '!=', self::METHOD_CREDIT)
            ->sum('amount'), 2);
    }

    /**
     * Money by method, split receipts broken into their parts; credit is left out.
     *
     * @param iterable<Payment> $payments with parts loaded
     * @return array<string, float>
     */
    public static function totalsByMethod(iterable $payments): array
    {
        $totals = [];
        foreach ($payments as $payment) {
            $parts = $payment->relationLoaded('parts') && $payment->parts->isNotEmpty()
                ? $payment->parts->map(fn (PaymentPart $p) => [$p->method, (float) $p->amount])
                : collect([[$payment->method, (float) $payment->amount]]);
            foreach ($parts as [$method, $amount]) {
                if ($method === self::METHOD_CREDIT) {
                    continue;
                }
                $totals[$method] = round(($totals[$method] ?? 0) + $amount, 2);
            }
        }

        return $totals;
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
