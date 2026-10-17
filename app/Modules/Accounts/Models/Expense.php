<?php

namespace App\Modules\Accounts\Models;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/**
 * Money spent on the business: paid in cash, from a bank, from customer money an
 * employee holds, or owed to a supplier.
 */
class Expense extends Model
{
    public const POSTED = 'posted';
    public const CANCELLED = 'cancelled';

    public const PAID_VIA = ['cash', 'bank', 'staff_custody', 'credit'];

    protected $fillable = [
        'branch_id', 'number', 'expense_date', 'account_id', 'paid_to', 'supplier_id', 'amount', 'gst_bill',
        'gst_rate', 'gst_amount', 'supplier_gstin', 'bill_number', 'paid_via', 'bank_account_id', 'paid_by_user_id',
        'job_card_id', 'note', 'bill_disk', 'bill_path', 'status', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'gst_bill' => 'boolean',
        'gst_rate' => 'decimal:2',
        'gst_amount' => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class)->withoutGlobalScopes();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateNumber(): string
    {
        $prefix = 'EXP-' . now()->format('ym') . '-';
        $last = static::where('number', 'like', $prefix . '%')->orderByDesc('id')->value('number');

        return $prefix . str_pad((string) ($last ? (int) substr($last, strlen($prefix)) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
