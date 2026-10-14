<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Customer-reported critical issues must be resolved or deferred before the trial drive.
 */
class NoOpenCriticalIssues implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        $open = $jobCard->issues()
            ->where('priority', 'critical')
            ->whereIn('status', ['open', 'in_progress'])
            ->get(['id', 'description']);

        return $open->isEmpty()
            ? null
            : new GuardFailure(
                'critical_issues_open',
                $open->count() . ' critical customer ' . ($open->count() === 1 ? 'issue is' : 'issues are') . ' still open.',
                ['issues' => $open->toArray()]
            );
    }
}
