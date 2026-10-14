<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

class JobCardAddon extends Model
{
    protected $fillable = [
        'job_card_id',
        'addon_id',
        'addon_name',
        'price',
        'discount',
        'final_price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'final_price' => 'decimal:2',
    ];

    /**
     * Job card.
     */
    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    /**
     * Service addon.
     */
    public function addon()
    {
        return $this->belongsTo(ServiceAddon::class, 'addon_id');
    }

    /**
     * Calculate final price on save.
     */
    protected static function booted(): void
    {
        static::saving(function ($item) {
            $item->final_price = $item->price - $item->discount;
        });

        static::saved(function ($item) {
            $item->jobCard->calculateTotals();
        });

        static::deleted(function ($item) {
            $item->jobCard->calculateTotals();
        });
    }
}
