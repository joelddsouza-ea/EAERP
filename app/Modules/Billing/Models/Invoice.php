<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;

class Invoice extends BranchScopedModel
{
    protected $fillable = [
        'branch_id',
        'job_card_id',
        'customer_id',
        'invoice_number',
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
        'is_igst',
        'notes',
        'terms',
        'created_by',
        'issued_at',
        'cancelled_at',
        'cancellation_reason',
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
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Customer.
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Created by user.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Invoice items.
     */
    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Payments.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Credit notes.
     */
    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Generate invoice number.
     */
    public static function generateInvoiceNumber(int $branchId): string
    {
        $prefix = 'INV';
        $date = now()->format('ymd');
        $branchCode = str_pad($branchId, 2, '0', STR_PAD_LEFT);

        $lastInvoice = static::where('invoice_number', 'like', "{$prefix}{$date}{$branchCode}%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        if ($lastInvoice) {
            $lastSeq = (int) substr($lastInvoice->invoice_number, -4);
            $seq = str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $seq = '0001';
        }

        return "{$prefix}{$date}{$branchCode}{$seq}";
    }

    /**
     * Calculate totals.
     */
    public function calculateTotals(): void
    {
        $items = $this->items;

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
        $this->total_amount = $this->taxable_amount + $this->total_tax - $this->discount;
        $this->balance_amount = $this->total_amount - $this->paid_amount;

        $this->save();
    }

    /**
     * Update payment status.
     */
    public function updatePaymentStatus(): void
    {
        $this->paid_amount = $this->payments()->where('status', 'completed')->sum('amount');
        $this->balance_amount = $this->total_amount - $this->paid_amount;

        if ($this->balance_amount <= 0) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        }

        $this->save();
    }

    /**
     * Scope by status.
     */
    public function scopeStatus($query, string|array $status)
    {
        return $query->whereIn('status', (array) $status);
    }

    /**
     * Scope to unpaid invoices.
     */
    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['issued', 'partial']);
    }

    /**
     * Scope for date range.
     */
    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('invoice_date', [$from, $to]);
    }
}
