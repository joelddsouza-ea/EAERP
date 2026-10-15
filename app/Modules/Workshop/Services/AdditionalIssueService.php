<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\SparePartRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Additional Issues lines on the job sheet: customer complaints (from intake, kept by
 * the advisor) and the technician's own findings. Only the assigned technician adds
 * photos/videos, marks lines Done or Deferred, and lists parts.
 */
class AdditionalIssueService
{
    private const OPEN = ['open', 'in_progress'];

    public function __construct(
        protected JobSheetService $sheets,
        protected IssueLineRules $rules,
        protected ObservationService $observations,
    ) {}

    public function isTechnician(JobCard $jobCard, User $user): bool
    {
        return $this->sheets->isAssignedTechnician($jobCard, $user);
    }

    /**
     * The technician adds his findings with photo/video evidence of the faulty part;
     * anyone else adds a customer complaint line.
     *
     * @param UploadedFile[] $files
     * @throws JobSheetException
     */
    public function create(JobCard $jobCard, array $data, User $user, array $files = []): JobCardIssue
    {
        $isTechnician = $this->isTechnician($jobCard, $user);

        if ($isTechnician && count($files) < IssueLineRules::minProblemMedia()) {
            throw new JobSheetException('Add a photo or video of the faulty part.', [
                'files' => ['Take a photo or video of the faulty part.'],
            ]);
        }

        return DB::transaction(function () use ($jobCard, $data, $user, $files, $isTechnician) {
            $issue = $jobCard->issues()->create([
                'description' => $data['description'],
                'priority' => $data['priority'] ?? 'normal',
                'source' => $isTechnician ? JobCardIssue::SOURCE_TECHNICIAN : JobCardIssue::SOURCE_CUSTOMER,
                'status' => 'open',
                'reported_by' => $user->id,
                'sort_order' => (int) $jobCard->issues()->max('sort_order') + 1,
            ]);

            foreach ($files as $file) {
                $this->sheets->storeMedia(
                    $jobCard, $file, 'job_card_issue', $issue->id, "issues/{$issue->id}",
                    "Issue: {$issue->description} — {$jobCard->job_number}", $user,
                );
            }

            return $issue;
        });
    }

    /**
     * Customer complaints captured at intake, one line each.
     *
     * @param string[] $lines
     */
    public function createComplaints(JobCard $jobCard, array $lines, ?User $user): void
    {
        $order = (int) $jobCard->issues()->max('sort_order');
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $jobCard->issues()->create([
                'description' => $line,
                'priority' => 'normal',
                'source' => JobCardIssue::SOURCE_CUSTOMER,
                'status' => 'open',
                'reported_by' => $user?->id,
                'sort_order' => ++$order,
            ]);
        }
    }

    /**
     * @throws JobSheetException
     */
    public function update(JobCard $jobCard, JobCardIssue $issue, array $data, User $user): JobCardIssue
    {
        $isTechnician = $this->isTechnician($jobCard, $user);

        // Text: the technician's own lines are his; customer complaints are the advisor's;
        // a flagged activity's line follows the activity's remarks
        if (array_intersect_key($data, array_flip(['description', 'priority']))) {
            if ($issue->source === JobCardIssue::SOURCE_ACTIVITY) {
                throw new JobSheetException('Edit the remarks on the flagged activity to change this line.');
            }
            $owner = $issue->source === JobCardIssue::SOURCE_TECHNICIAN;
            if ($owner !== $isTechnician) {
                throw new JobSheetException(
                    $owner ? 'Only the technician edits his own findings.' : 'Customer complaints are edited by the advisor.',
                    [],
                    403
                );
            }
        }

        if (isset($data['status']) && $data['status'] !== $issue->status) {
            if (!$isTechnician) {
                throw new JobSheetException('View only: the assigned technician marks lines done or deferred.', [], 403);
            }

            if ($data['status'] === 'resolved') {
                $issue->load(IssueLineRules::RELATIONS);
                if ($shortfall = $this->rules->doneShortfall($issue)) {
                    throw new JobSheetException($shortfall, ['status' => [$shortfall]]);
                }
            }

            if ($data['status'] === 'deferred' && !trim((string) ($data['resolution_notes'] ?? $issue->resolution_notes))) {
                throw new JobSheetException('Say why the line is deferred.', ['resolution_notes' => ['Add a note when deferring a line.']]);
            }

            $closing = in_array($data['status'], ['resolved', 'deferred'], true);
            $data['resolved_by'] = $closing ? $user->id : null;
            $data['resolved_at'] = $closing ? now() : null;
        }

        return DB::transaction(function () use ($issue, $data, $user) {
            $statusChanged = isset($data['status']) && $data['status'] !== $issue->status;
            $issue->update($data);
            $this->observations->syncDeferredIssue($issue);

            if ($statusChanged && $issue->source_item_id) {
                $this->syncFlaggedActivity($issue, $user);
            }

            return $issue;
        });
    }

    /**
     * A flagged activity follows its line: done on the line → done on the service sheet
     * (no need to tick it again); re-opened → back to pending.
     */
    protected function syncFlaggedActivity(JobCardIssue $issue, User $user): void
    {
        $item = JobCardChecklistItem::find($issue->source_item_id);
        if (!$item) {
            return;
        }

        if ($issue->status === 'resolved' && $item->status !== JobCardChecklistItem::STATUS_DONE) {
            $item->update([
                'status' => JobCardChecklistItem::STATUS_DONE,
                'is_completed' => true,
                'completed_by' => $user->id,
                'completed_at' => now(),
            ]);
        } elseif (in_array($issue->status, ['open', 'in_progress'], true) && $item->status === JobCardChecklistItem::STATUS_DONE) {
            $item->update([
                'status' => JobCardChecklistItem::STATUS_PENDING,
                'is_completed' => false,
                'completed_by' => null,
                'completed_at' => null,
            ]);
        }
    }

    /**
     * A line can be removed while nothing has been recorded on it.
     *
     * @throws JobSheetException
     */
    public function delete(JobCard $jobCard, JobCardIssue $issue, User $user): void
    {
        if ($issue->source === JobCardIssue::SOURCE_ACTIVITY) {
            throw new JobSheetException('Clear the ⚠ flag on the activity to remove this line.');
        }
        $owner = $issue->source === JobCardIssue::SOURCE_TECHNICIAN;
        if ($owner !== $this->isTechnician($jobCard, $user)) {
            throw new JobSheetException(
                $owner ? 'Only the technician removes his own findings.' : 'Customer complaints are removed by the advisor.',
                [],
                403
            );
        }

        $hasWork = $issue->media()->exists()
            || $issue->partRequests()->whereNotIn('status', [SparePartRequest::DRAFT, SparePartRequest::CANCELLED])->exists();
        if ($hasWork) {
            throw new JobSheetException('This line has photos or parts on it: defer it instead of removing it.');
        }

        DB::transaction(function () use ($issue) {
            $issue->partRequests()->where('status', SparePartRequest::DRAFT)->delete();
            $issue->delete();
        });
    }

    /**
     * Photo/video of the problem, or (tag fitted_part) of the new part after fitting.
     *
     * @throws JobSheetException
     */
    public function attachMedia(
        JobCard $jobCard,
        JobCardIssue $issue,
        UploadedFile $file,
        User $user,
        ?string $tag,
        ?UploadedFile $poster = null,
        array $meta = [],
    ): JobCardMedia {
        $this->assertTechnicianCanWork($jobCard, $issue, $user);

        // The new part is photographed once it has arrived and is fitted
        if ($tag === JobCardMedia::TAG_FITTED_PART) {
            $issue->load(IssueLineRules::RELATIONS);
            if (!$this->rules->partsArrived($issue)) {
                throw new JobSheetException('The new part has not arrived yet. You will be notified when it does.', [
                    'tag' => ['The new part has not arrived yet.'],
                ]);
            }
        }

        $label = $tag === JobCardMedia::TAG_FITTED_PART ? 'New part fitted' : 'Faulty part';
        $media = $this->sheets->storeMedia(
            $jobCard, $file, 'job_card_issue', $issue->id, "issues/{$issue->id}",
            "{$label}: {$issue->description} — {$jobCard->job_number}", $user, $poster, $meta,
        );

        if ($tag) {
            $media->update(['tag' => $tag]);
        }
        if ($issue->status === 'open') {
            $issue->update(['status' => 'in_progress']);
        }

        return $media;
    }

    /**
     * @throws JobSheetException
     */
    public function deleteMedia(JobCard $jobCard, JobCardIssue $issue, JobCardMedia $media, User $user): void
    {
        $this->assertTechnicianCanWork($jobCard, $issue, $user);

        if ($media->attachable_type !== 'job_card_issue' || $media->attachable_id !== $issue->id) {
            throw new JobSheetException('Media not found on this line.', [], 404);
        }

        $media->delete();

        // A Done line that no longer has its evidence goes back to in progress
        $issue->load(IssueLineRules::RELATIONS);
        if ($issue->status === 'resolved' && $this->rules->doneShortfall($issue)) {
            $issue->update(['status' => 'in_progress', 'resolved_by' => null, 'resolved_at' => null]);
        }
    }

    /**
     * @throws JobSheetException
     */
    private function assertTechnicianCanWork(JobCard $jobCard, JobCardIssue $issue, User $user): void
    {
        if ($jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }
        if (!$this->isTechnician($jobCard, $user)) {
            throw new JobSheetException('View only: the assigned technician adds photos and videos.', [], 403);
        }
        if ($issue->status === 'deferred') {
            throw new JobSheetException('This line is deferred. Re-open it to work on it.');
        }
    }
}
