<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A head in the chart of accounts.
 */
class Account extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    protected $fillable = ['code', 'name', 'type', 'sub_type', 'group', 'is_system', 'is_expense_head', 'is_active'];

    protected $casts = [
        'is_system' => 'boolean',
        'is_expense_head' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Assets and expenses grow on the debit side.
     */
    public function isDebitNature(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
