<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An ad campaign (or any named push); its monthly spend gives cost per lead and ROI.
 */
class Campaign extends Model
{
    protected $fillable = ['lead_source_id', 'name', 'external_ref', 'is_active', 'whatsapp_start_text'];

    protected $casts = ['is_active' => 'boolean'];

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function spends()
    {
        return $this->hasMany(CampaignSpend::class);
    }
}
