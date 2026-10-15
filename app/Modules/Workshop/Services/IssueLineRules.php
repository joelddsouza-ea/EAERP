<?php

namespace App\Modules\Workshop\Services;

use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\SparePartRequest;

/**
 * The order of work on an Additional Issues line:
 * 1. photo/video evidence of the faulty part (a flagged activity's own photos count);
 * 2. the parts required, sent to the advisor → coordinator prices → estimate → customer approval → procurement;
 * 3. once the new part has arrived, a photo/video of it fitted;
 * 4. Done. Every line must be Done, Deferred, or declined by the customer before the final job sheet submit.
 * Expects issues with media, partRequests and sourceItem.media loaded.
 */
class IssueLineRules
{
    /** Still with the advisor / coordinator / customer */
    private const IN_PROCESS = [SparePartRequest::DRAFT, SparePartRequest::PROPOSED, SparePartRequest::REQUESTED, SparePartRequest::QUOTED];
    /** Approved, not here yet */
    private const AWAITED = [SparePartRequest::APPROVED, SparePartRequest::ORDERED];
    /** Here, to be fitted */
    private const ARRIVED = [SparePartRequest::RECEIVED, SparePartRequest::ISSUED];

    public const RELATIONS = ['media', 'partRequests', 'sourceItem.media'];

    public static function minProblemMedia(): int
    {
        return (int) WorkshopSettings::get('issues.min_problem_media');
    }

    public static function minFittedMedia(): int
    {
        return (int) WorkshopSettings::get('issues.min_fitted_media');
    }

    /**
     * Photos/videos of the fault: the line's own, plus those on the flagged activity it came from.
     */
    public function problemMediaCount(JobCardIssue $issue): int
    {
        return $issue->media->where('tag', '!=', JobCardMedia::TAG_FITTED_PART)->count()
            + ($issue->sourceItem?->media->count() ?? 0);
    }

    public function fittedMediaCount(JobCardIssue $issue): int
    {
        return $issue->media->where('tag', JobCardMedia::TAG_FITTED_PART)->count();
    }

    /**
     * Parts can be listed once the fault is evidenced.
     */
    public function canListParts(JobCardIssue $issue): bool
    {
        return $this->problemMediaCount($issue) >= self::minProblemMedia();
    }

    private function partsIn(JobCardIssue $issue, array $statuses): bool
    {
        return $issue->partRequests->contains(fn (SparePartRequest $p) => in_array($p->status, $statuses, true));
    }

    public function partsInProcess(JobCardIssue $issue): bool
    {
        return $this->partsIn($issue, self::IN_PROCESS);
    }

    public function partsAwaited(JobCardIssue $issue): bool
    {
        return $this->partsIn($issue, self::AWAITED);
    }

    public function partsArrived(JobCardIssue $issue): bool
    {
        return $this->partsIn($issue, self::ARRIVED);
    }

    /** Approved parts on the line: a photo/video of the new part fitted is needed before Done */
    public function hasPartsToFit(JobCardIssue $issue): bool
    {
        return $this->partsAwaited($issue) || $this->partsArrived($issue);
    }

    /**
     * What is missing before the line can be marked Done, or null.
     */
    public function doneShortfall(JobCardIssue $issue): ?string
    {
        $evidenceNeeded = $issue->source !== JobCardIssue::SOURCE_CUSTOMER || $issue->partRequests->isNotEmpty();
        if ($evidenceNeeded && $this->problemMediaCount($issue) < self::minProblemMedia()) {
            return 'Add a photo or video of the faulty part.';
        }

        if ($this->partsInProcess($issue)) {
            return 'Waiting for the parts estimate and the customer\'s approval.';
        }

        if ($this->partsAwaited($issue)) {
            return 'Waiting for the new part to arrive.';
        }

        if ($this->partsArrived($issue) && $this->fittedMediaCount($issue) < self::minFittedMedia()) {
            return 'Add a photo or video of the new part after fitting it.';
        }

        return null;
    }

    /**
     * The customer declined every part on this line: nothing left to do here (it is an observation).
     */
    public function declinedByCustomer(JobCardIssue $issue): bool
    {
        $parts = $issue->partRequests->where('status', '!=', SparePartRequest::CANCELLED);

        return $parts->isNotEmpty() && $parts->every(fn (SparePartRequest $p) => $p->status === SparePartRequest::DECLINED);
    }

    /**
     * Lines that stop the technician's final job sheet submission.
     *
     * @return array<int, array{issue_id: int, item_id: null, item_name: string, section_name: string, code: string, message: string}>
     */
    public function problems(JobCard $jobCard): array
    {
        $issues = $jobCard->issues()->with(self::RELATIONS)->get();
        $problems = [];

        foreach ($issues as $issue) {
            if ($issue->status === 'deferred' || $this->declinedByCustomer($issue)) {
                continue;
            }

            $message = $issue->status === 'resolved' ? $this->doneShortfall($issue) : 'Not done yet';

            if ($message) {
                $problems[] = [
                    'issue_id' => $issue->id,
                    'item_id' => null,
                    'item_name' => $issue->description,
                    'section_name' => 'Additional Issues',
                    'code' => $issue->status === 'resolved' ? 'media_missing' : 'issue_open',
                    'message' => $message,
                ];
            }
        }

        return $problems;
    }
}
