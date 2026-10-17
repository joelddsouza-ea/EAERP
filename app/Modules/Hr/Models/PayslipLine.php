<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class PayslipLine extends Model
{
    protected $fillable = ['payslip_id', 'code', 'label', 'kind', 'amount', 'source_type', 'source_id', 'edited_note', 'sort_order'];

    protected $casts = ['amount' => 'float'];

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }
}
