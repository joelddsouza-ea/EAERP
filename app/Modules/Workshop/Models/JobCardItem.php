<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

class JobCardItem extends Model
{
    protected $fillable = [
        'job_card_id',
        'estimate_line_key',
        'type',
        'description',
        'part_number',
        'quantity',
        'unit',
        'unit_price',
        'discount',
        'tax_rate',
        'tax_amount',
        'total',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Calculate total on save.
     */
    protected static function booted(): void
    {
        static::saving(function ($item) {
            $subtotal = ($item->quantity * $item->unit_price) - $item->discount;
            $item->tax_amount = $subtotal * ($item->tax_rate / 100);
            $item->total = $subtotal + $item->tax_amount;
        });

        static::saved(function ($item) {
            $item->jobCard->calculateTotals();
        });

        static::deleted(function ($item) {
            $item->jobCard->calculateTotals();
        });
    }
}
