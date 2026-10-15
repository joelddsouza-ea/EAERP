<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Database\Eloquent\Model;

/**
 * Permission for the car to leave. Issued when the bill is paid, or earlier by an
 * owner/manager with a reason. The job is delivered when the gate records "Vehicle out".
 */
class GatePass extends Model
{
    protected $fillable = [
        'branch_id',
        'job_card_id',
        'invoice_id',
        'number',
        'amount_due',
        'override',
        'override_reason',
        'issued_by',
        'issued_at',
        'vehicle_out_by',
        'vehicle_out_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'override' => 'boolean',
        'issued_at' => 'datetime',
        'vehicle_out_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function vehicleOutBy()
    {
        return $this->belongsTo(User::class, 'vehicle_out_by');
    }

    public static function generateNumber(int $branchId): string
    {
        $prefix = 'GP' . now()->format('ymd') . str_pad($branchId, 2, '0', STR_PAD_LEFT);
        $last = static::where('number', 'like', "{$prefix}%")->orderByDesc('number')->value('number');

        return $prefix . str_pad($last ? (int) substr($last, -4) + 1 : 1, 4, '0', STR_PAD_LEFT);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }
}
