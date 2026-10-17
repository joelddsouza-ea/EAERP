<?php

namespace App\Modules\Accounts\Models;

use App\Modules\Accounts\Support\ChartOfAccounts;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank account of the business; its money is kept on its own ledger head.
 */
class BankAccount extends Model
{
    protected $fillable = ['account_id', 'name', 'account_number', 'ifsc', 'is_default', 'is_active'];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Card, UPI, transfers and cheques land here when no bank is chosen.
     */
    public static function defaultAccountId(): int
    {
        $bank = static::where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();

        return $bank ? (int) $bank->account_id : ChartOfAccounts::id(ChartOfAccounts::BANK);
    }
}
