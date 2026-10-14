<?php

namespace App\Modules\Workshop\Workflow;

use App\Modules\Workshop\Models\JobCard;

/**
 * A precondition on a status transition. Returns null when satisfied.
 */
interface Guard
{
    public function check(JobCard $jobCard): ?GuardFailure;
}
