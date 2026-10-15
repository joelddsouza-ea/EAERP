<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Work resumes only once the customer has approved or declined every additional
 * item on the estimate they were sent.
 */
class EstimateResponded implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        $estimate = JobCardEstimate::where('job_card_id', $jobCard->id)
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->latest('version')
            ->first();

        if (!$estimate) {
            return null;
        }

        $pending = $estimate->lines()
            ->where('approval_status', JobCardEstimateLine::PENDING)
            ->get(['line_key', 'description']);

        return $pending->isEmpty()
            ? null
            : new GuardFailure(
                'estimate_pending',
                $pending->count() . ' estimate ' . ($pending->count() === 1 ? 'item is' : 'items are') . ' still waiting for the customer\'s approval.',
                ['lines' => $pending->toArray()]
            );
    }
}
