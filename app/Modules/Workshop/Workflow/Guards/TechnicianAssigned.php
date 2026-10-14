<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

class TechnicianAssigned implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        return $jobCard->technician_id
            ? null
            : new GuardFailure('technician_missing', 'Assign a technician before starting work.');
    }
}
