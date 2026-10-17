<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A nurturing / retention sequence: timed steps started by a trigger.
 */
class Journey extends Model
{
    public const TRIGGERS = [
        'new_lead' => 'New lead, not booked yet',
        'lost_not_now' => 'Lead closed as "not now"',
        'no_show' => 'Booked but did not come',
        'after_delivery' => 'Car delivered (thank you, feedback)',
        'service_due' => 'Next service due (days before / after)',
        'insurance_due' => 'Insurance expiry (days before)',
        'pending_work' => 'Pending work after delivery',
        'win_back' => 'No visit for a long time',
        'referral_thanks' => 'Referral rewarded',
    ];

    /** Day 0 is a due date; offsets may be negative */
    public const DUE_DATE_TRIGGERS = ['service_due', 'insurance_due'];

    /** Marketing (counted against the frequency cap); the rest are service messages */
    public const MARKETING_TRIGGERS = ['new_lead', 'lost_not_now', 'pending_work', 'win_back', 'service_due', 'insurance_due', 'no_show'];

    protected $fillable = ['name', 'trigger', 'branch_id', 'is_active', 'description'];

    protected $casts = ['is_active' => 'boolean'];

    public function steps()
    {
        return $this->hasMany(JourneyStep::class)->orderBy('day_offset')->orderBy('sort_order');
    }
}
