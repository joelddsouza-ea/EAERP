<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobLifecycleService;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Final QC starts once washing is recorded as done.
 */
class WashRecorded implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        return app(JobLifecycleService::class)->washDone($jobCard)
            ? null
            : new GuardFailure('wash_missing', 'Record "Washing done" first.');
    }
}
