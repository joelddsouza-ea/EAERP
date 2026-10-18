<?php

namespace App\Modules\CustomerApp\Models;

use App\Modules\Workshop\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A one-time sign-in code: sent by WhatsApp (Interakt) when that is on, else read out by staff.
 */
class CustomerOtp extends Model
{
    public const VIA_INTERAKT = 'interakt';
    public const VIA_STAFF = 'staff';

    protected $fillable = ['customer_id', 'phone', 'code_hash', 'code_encrypted', 'delivered_via', 'attempts', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function code(): string
    {
        return Crypt::decryptString($this->code_encrypted);
    }

    public function isOpen(): bool
    {
        return !$this->used_at && $this->expires_at->isFuture() && $this->attempts < 5;
    }
}
