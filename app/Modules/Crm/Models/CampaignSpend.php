<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignSpend extends Model
{
    protected $fillable = ['campaign_id', 'period', 'amount', 'created_by'];

    protected $casts = ['amount' => 'float'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
