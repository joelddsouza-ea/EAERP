<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Models\ObservationRemark;

/**
 * Keeps the Observations list in step with work not done: lines the customer
 * declined on the estimate, and Additional Issues lines the technician deferred.
 * These appear on the service report with their remark.
 */
class ObservationService
{
    /**
     * One observation per declined additional line ("Customer not approved");
     * removed again if the customer later approves it.
     */
    public function syncDeclinedLines(JobCard $jobCard, JobCardEstimate $estimate): void
    {
        $declined = $estimate->lines()
            ->where('section', Line::SECTION_ADDITIONAL)
            ->where('approval_status', Line::DECLINED)
            ->get()
            ->keyBy('line_key');

        $existing = JobCardObservation::where('job_card_id', $jobCard->id)
            ->where('source', JobCardObservation::SOURCE_ESTIMATE_DECLINED)
            ->get()
            ->keyBy('estimate_line_key');

        foreach ($existing as $key => $observation) {
            if (!$declined->has($key)) {
                $observation->delete();
            }
        }

        $remarkId = ObservationRemark::idFor(ObservationRemark::CUSTOMER_NOT_APPROVED);
        foreach ($declined as $key => $line) {
            if ($existing->has($key)) {
                $existing->get($key)->update(['text' => $line->description]);
                continue;
            }
            JobCardObservation::create([
                'job_card_id' => $jobCard->id,
                'source' => JobCardObservation::SOURCE_ESTIMATE_DECLINED,
                'estimate_line_key' => $key,
                'job_card_issue_id' => $line->job_card_issue_id,
                'text' => $line->description,
                'remark_id' => $remarkId,
                'customer_visible' => true,
                'sort_order' => $this->nextOrder($jobCard),
            ]);
        }
    }

    /**
     * A deferred line becomes an observation ("Fix at next service"); un-deferring removes it.
     */
    public function syncDeferredIssue(JobCardIssue $issue): void
    {
        $existing = JobCardObservation::where('job_card_issue_id', $issue->id)
            ->where('source', JobCardObservation::SOURCE_ISSUE_DEFERRED)
            ->first();

        if ($issue->status !== 'deferred') {
            $existing?->delete();

            return;
        }

        $text = trim($issue->description . ($issue->resolution_notes ? " — {$issue->resolution_notes}" : ''));

        if ($existing) {
            $existing->update(['text' => $text]);

            return;
        }

        JobCardObservation::create([
            'job_card_id' => $issue->job_card_id,
            'source' => JobCardObservation::SOURCE_ISSUE_DEFERRED,
            'job_card_issue_id' => $issue->id,
            'text' => $text,
            'remark_id' => ObservationRemark::idFor(ObservationRemark::FIX_AT_NEXT_SERVICE),
            'customer_visible' => true,
            'sort_order' => $this->nextOrder($issue->job_card_id),
        ]);
    }

    private function nextOrder(JobCard|int $jobCard): int
    {
        $id = $jobCard instanceof JobCard ? $jobCard->id : $jobCard;

        return (int) JobCardObservation::where('job_card_id', $id)->max('sort_order') + 1;
    }
}
