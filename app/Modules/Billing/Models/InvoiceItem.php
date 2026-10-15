<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'estimate_line_key',
        'section',
        'type',
        'hsn_code',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'discount',
        'taxable_amount',
        'tax_rate',
        'gst_inclusive',
        'gst_applicable',
        'cgst',
        'sgst',
        'igst',
        'total',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'gst_inclusive' => 'boolean',
        'gst_applicable' => 'boolean',
        'cgst' => 'decimal:2',
        'sgst' => 'decimal:2',
        'igst' => 'decimal:2',
        'total' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    /**
     * GST for a line: [taxable, tax]. Inclusive prices have the tax extracted
     * (Rs 118 at 18% = 100 + 18); exclusive prices get it added on top. Within a
     * state the tax is two equal halves (CGST = SGST), each rounded to the paisa.
     *
     * @return array{0: float, 1: float}
     */
    public static function split(float $amount, float $rate, bool $inclusive, bool $igst = false): array
    {
        if ($rate <= 0) {
            return [round($amount, 2), 0.0];
        }

        $parts = $igst ? 1 : 2;
        $part = $inclusive
            ? round($amount * ($rate / $parts) / (100 + $rate), 2)
            : round($amount * ($rate / $parts) / 100, 2);
        $tax = round($part * $parts, 2);

        return [round($inclusive ? $amount - $tax : $amount, 2), $tax];
    }

    protected static function booted(): void
    {
        static::saving(function (InvoiceItem $item) {
            $item->gst_applicable ??= true;
            if (!$item->gst_applicable) {
                $item->tax_rate = 0;
            }

            $amount = (float) $item->quantity * (float) $item->unit_price - (float) $item->discount;
            $igst = (bool) $item->invoice?->is_igst;
            [$taxable, $tax] = self::split($amount, (float) $item->tax_rate, (bool) $item->gst_inclusive, $igst);
            $item->taxable_amount = $taxable;
            $item->igst = $igst ? $tax : 0;
            $item->cgst = $igst ? 0 : round($tax / 2, 2);
            $item->sgst = $igst ? 0 : round($tax / 2, 2);

            $item->total = round($taxable + $tax, 2);
        });

        static::saved(fn (InvoiceItem $item) => $item->invoice->calculateTotals());
        static::deleted(fn (InvoiceItem $item) => $item->invoice->calculateTotals());
    }
}
