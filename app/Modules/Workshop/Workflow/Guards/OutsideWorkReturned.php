<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobOutsideWork;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * A car at an outside partner (washing, wheel alignment) must be back before the final QC.
 */
class OutsideWorkReturned implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        $away = JobOutsideWork::with('partner:id,name')
            ->where('job_card_id', $jobCard->id)
            ->whereIn('status', JobOutsideWork::OPEN)
            ->get();

        return $away->isEmpty()
            ? null
            : new GuardFailure(
                'outside_work_open',
                'The car is not back from ' . $away->map(fn (JobOutsideWork $w) => strtolower($w->kindLabel()) . ' (' . $w->partner?->name . ')')->implode(', ') . ' yet.',
            );
    }
}
