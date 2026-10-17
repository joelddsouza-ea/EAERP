<?php

namespace App\Modules\Accounts\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One voucher: balanced debit and credit lines, tied to what caused it.
 */
class JournalEntry extends Model
{
    public const POSTED = 'posted';
    public const REVERSED = 'reversed';

    public const VOUCHER_TYPES = [
        'sale' => 'Sales',
        'receipt' => 'Receipt',
        'refund' => 'Refund',
        'purchase' => 'Purchase',
        'supplier_payment' => 'Supplier payment',
        'stock' => 'Stock',
        'expense' => 'Expense',
        'payroll' => 'Payroll',
        'salary_payment' => 'Salary payment',
        'advance' => 'Salary advance',
        'custody' => 'Staff hand-over',
        'contra' => 'Contra',
        'journal' => 'Journal',
        'opening' => 'Opening balance',
        'reversal' => 'Reversal',
    ];

    protected $fillable = [
        'branch_id', 'number', 'entry_date', 'voucher_type', 'source_type', 'source_id', 'narration',
        'status', 'reversal_of', 'amount', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
