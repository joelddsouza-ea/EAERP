<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to a supplier against credit purchases.
 */
class SupplierPayment extends Model
{
    protected $fillable = ['branch_id', 'supplier_id', 'amount', 'method', 'reference_number', 'paid_on', 'notes', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
