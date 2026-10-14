<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'type',
        'hsn_code',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'discount',
        'taxable_amount',
        'tax_rate',
        'cgst',
        'sgst',
        'igst',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'cgst' => 'decimal:2',
        'sgst' => 'decimal:2',
        'igst' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Invoice.
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Calculate amounts on save.
     */
    protected static function booted(): void
    {
        static::saving(function ($item) {
            $subtotal = $item->quantity * $item->unit_price;
            $item->taxable_amount = $subtotal - $item->discount;
            $taxAmount = $item->taxable_amount * ($item->tax_rate / 100);

            $invoice = $item->invoice ?? Invoice::find($item->invoice_id);

            if ($invoice && $invoice->is_igst) {
                $item->igst = round($taxAmount, 2);
                $item->cgst = 0;
                $item->sgst = 0;
            } else {
                $split = TaxRate::splitGst($taxAmount);
                $item->cgst = $split['cgst'];
                $item->sgst = $split['sgst'];
                $item->igst = 0;
            }

            $item->total = $item->taxable_amount + $item->cgst + $item->sgst + $item->igst;
        });

        static::saved(function ($item) {
            $item->invoice->calculateTotals();
        });

        static::deleted(function ($item) {
            $item->invoice->calculateTotals();
        });
    }
}
