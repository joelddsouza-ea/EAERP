<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    public const PARTY_CUSTOMER = 'customer';
    public const PARTY_SUPPLIER = 'supplier';
    public const PARTY_USER = 'user';

    protected $fillable = ['journal_entry_id', 'account_id', 'party_type', 'party_id', 'debit', 'credit', 'memo'];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
