<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Billing\Models\GatePass;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * No gate pass, no delivery. The gate pass needs full payment or an owner/manager
 * override, so this check itself can't be overridden.
 */
class GatePassIssued implements Guard
{
    public const CODE = 'gate_pass_missing';

    public function check(JobCard $jobCard): ?GuardFailure
    {
        return GatePass::where('job_card_id', $jobCard->id)->active()->exists()
            ? null
            : new GuardFailure(self::CODE, 'No gate pass yet: it is issued once the bill is paid in full (an owner/manager can issue it earlier, with a reason).');
    }
}
