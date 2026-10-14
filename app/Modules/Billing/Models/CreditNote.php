<?php

namespace App\Modules\Billing\Models;

use App\Core\Models\BranchScopedModel;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Customer;

class CreditNote extends BranchScopedModel
{
    protected $fillable = [
        'branch_id',
        'invoice_id',
        'customer_id',
        'credit_note_number',
        'credit_note_date',
        'amount',
        'reason',
        'status',
        'created_by',
    ];

    protected $casts = [
        'credit_note_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Invoice.
     */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
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
     * Generate credit note number.
     */
    public static function generateCreditNoteNumber(int $branchId): string
    {
        $prefix = 'CN';
        $date = now()->format('ymd');
        $branchCode = str_pad($branchId, 2, '0', STR_PAD_LEFT);

        $lastNote = static::where('credit_note_number', 'like', "{$prefix}{$date}{$branchCode}%")
            ->orderBy('credit_note_number', 'desc')
            ->first();

        if ($lastNote) {
            $lastSeq = (int) substr($lastNote->credit_note_number, -4);
            $seq = str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $seq = '0001';
        }

        return "{$prefix}{$date}{$branchCode}{$seq}";
    }
}
