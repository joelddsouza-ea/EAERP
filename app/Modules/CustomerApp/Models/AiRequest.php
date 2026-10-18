<?php

namespace App\Modules\CustomerApp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One question to the AI assistant: what was asked, the answer, tokens and cost.
 */
class AiRequest extends Model
{
    protected $fillable = [
        'customer_id', 'vehicle_id', 'feature', 'model', 'question', 'photos', 'answer', 'stop_reason',
        'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_inr', 'error', 'outcome',
    ];

    protected $casts = [
        'answer' => 'array',
        'cost_inr' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(\App\Modules\Workshop\Models\Customer::class);
    }
}
