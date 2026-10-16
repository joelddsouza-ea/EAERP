<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;

/**
 * A bill. A job's final bill starts as a proforma (the final estimate the customer
 * pays against), which is either converted into a tax invoice or closed as it is.
 */
class Invoice extends BranchScopedModel
{
    public const KIND_PROFORMA = 'proforma';
    public const KIND_TAX = 'tax';

    // Proforma life cycle
    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const CONVERTED = 'converted';
    public const CLOSED = 'closed';
    // Tax invoice (payment state)
    public const ISSUED = 'issued';
    public const PARTIAL = 'partial';
    public const PAID = 'paid';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id',
        'job_card_id',
        'customer_id',
        'invoice_number',
        'kind',
        'proforma_id',
        'estimate_id',
        'invoice_date',
        'due_date',
        'subtotal',
        'discount',
        'discount_type',
        'taxable_amount',
        'cgst',
        'sgst',
        'igst',
        'total_tax',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'status',
        'place_of_supply',
        'customer_gstin',
        'is_igst',
        'notes',
        'terms',
        'created_by',
        'issued_at',
        'cancelled_at',
        'cancellation_reason',
        'approved_by',
        'approved_at',
        'closed_by',
        'closed_at',
        'close_note',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'cgst' => 'decimal:2',
        'sgst' => 'decimal:2',
        'igst' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
        'is_igst' => 'boolean',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'approved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function proforma()
    {
        return $this->belongsTo(self::class, 'proforma_id');
    }

    public function taxInvoice()
    {
        return $this->hasOne(self::class, 'proforma_id');
    }

    public function isProforma(): bool
    {
        return $this->kind === self::KIND_PROFORMA;
    }

    /**
     * Lines can still be added or removed: a proforma until it is converted or closed.
     */
    public function isEditable(): bool
    {
        return $this->isProforma()
            ? in_array($this->status, [self::DRAFT, self::APPROVED], true)
            : $this->status === self::DRAFT;
    }

    /**
     * The bill the customer is asked to pay: an approved proforma or a tax invoice.
     */
    public function isPayable(): bool
    {
        return $this->isProforma()
            ? in_array($this->status, [self::APPROVED, self::CLOSED], true)
            : in_array($this->status, [self::ISSUED, self::PARTIAL, self::PAID], true);
    }

    public static function generateInvoiceNumber(int $branchId, string $prefix = 'INV'): string
    {
        $date = now()->format('ymd');
        $branchCode = str_pad($branchId, 2, '0', STR_PAD_LEFT);

        $lastInvoice = static::withoutGlobalScopes()
            ->withTrashed()
            ->where('invoice_number', 'like', "{$prefix}{$date}{$branchCode}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        $seq = $lastInvoice ? (int) substr($lastInvoice->invoice_number, -4) + 1 : 1;

        return $prefix . $date . $branchCode . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Totals from the lines. Inclusive lines already hold their GST, exclusive lines
     * add it; the bill total is rounded to the rupee.
     */
    public function calculateTotals(): void
    {
        $items = $this->items()->get();

        $this->subtotal = $items->sum('taxable_amount') + $items->sum('discount');
        $this->taxable_amount = $items->sum('taxable_amount');

        if ($this->is_igst) {
            $this->igst = $items->sum('igst');
            $this->cgst = 0;
            $this->sgst = 0;
        } else {
            $this->cgst = $items->sum('cgst');
            $this->sgst = $items->sum('sgst');
            $this->igst = 0;
        }

        $this->total_tax = $this->cgst + $this->sgst + $this->igst;
        $this->total_amount = round((float) $this->taxable_amount + (float) $this->total_tax - (float) $this->discount);

        // A job's bill re-applies the job's payments to the new total (lines may have been removed)
        if ($this->job_card_id) {
            $this->updatePaymentStatus();

            return;
        }

        $this->balance_amount = (float) $this->total_amount - (float) $this->paid_amount;
        $this->save();
    }

    /**
     * Line discounts as shown above the taxable value: [value before discount, discount],
     * both before GST, so "value − discount = taxable value" adds up on the bill.
     *
     * @return array{0: float, 1: float}
     */
    public function discountBreakup(): array
    {
        $before = 0.0;
        foreach ($this->items as $item) {
            $gross = (float) $item->quantity * (float) $item->unit_price;
            [$taxable] = InvoiceItem::split($gross, (float) $item->tax_rate, (bool) $item->gst_inclusive, (bool) $this->is_igst);
            $before += $taxable;
        }
        $before = round($before, 2);

        return [$before, round(max(0, $before - (float) $this->taxable_amount), 2)];
    }

    /**
     * Difference between the rounded total and the line sums.
     */
    public function roundOff(): float
    {
        return round((float) $this->total_amount - ((float) $this->taxable_amount + (float) $this->total_tax - (float) $this->discount), 2);
    }

    /**
     * Paid so far. A job's bill counts every payment on the job (advance, during work,
     * final, less refunds and amounts moved to the customer's credit), so a balance
     * below zero is money to refund or keep as credit. A stand-alone invoice counts its
     * own payments.
     */
    public function updatePaymentStatus(): void
    {
        if ($this->job_card_id) {
            $this->paid_amount = (float) Payment::withoutGlobalScopes()
                ->where('job_card_id', $this->job_card_id)
                ->where('status', 'completed')
                ->sum('amount');
        } else {
            $this->paid_amount = $this->payments()->where('status', 'completed')->sum('amount');
        }

        $this->balance_amount = (float) $this->total_amount - (float) $this->paid_amount;

        // A tax invoice's status is its payment state; a proforma keeps its life-cycle status
        if (!$this->isProforma() && in_array($this->status, [self::ISSUED, self::PARTIAL, self::PAID], true)) {
            $this->status = $this->balance_amount <= 0 ? self::PAID : ((float) $this->paid_amount > 0 ? self::PARTIAL : self::ISSUED);
        }

        $this->save();
    }

    public function scopeStatus($query, string|array $status)
    {
        return $query->whereIn('status', (array) $status);
    }

    /**
     * Bills with money still to collect.
     */
    public function scopeUnpaid($query)
    {
        return $query->where(fn ($q) => $q
            ->whereIn('status', [self::ISSUED, self::PARTIAL])
            ->orWhere(fn ($q) => $q->where('kind', self::KIND_PROFORMA)->where('status', self::APPROVED)->where('balance_amount', '>', 0)));
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('invoice_date', [$from, $to]);
    }
}
