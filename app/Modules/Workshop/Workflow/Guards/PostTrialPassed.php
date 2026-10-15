<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobLifecycleService;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * The advisor's post-service trial drive must be recorded as OK before the wash.
 */
class PostTrialPassed implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        return app(JobLifecycleService::class)->postTrialResult($jobCard) === 'ok'
            ? null
            : new GuardFailure('post_trial_missing', 'Record the post-service trial drive first.');
    }
}
