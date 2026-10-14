<?php

namespace App\Modules\Workshop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Insurance claim header for body-shop jobs.
 */
class JobCardInsuranceDetail extends Model
{
    protected $fillable = [
        'job_card_id',
        'insurance_company',
        'claim_no',
        'survey_date',
        'surveyor_name',
        'surveyor_mobile',
    ];

    protected $casts = [
        'survey_date' => 'date',
    ];

    public function jobCard()
    {
        return $this->belongsTo(JobCard::class);
    }
}
