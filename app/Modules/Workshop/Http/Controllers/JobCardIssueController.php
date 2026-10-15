<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Http\Controllers\Concerns\ValidatesMediaUpload;
use App\Modules\Workshop\Http\Resources\JobCardMediaResource;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\ObservationRemark;
use App\Modules\Workshop\Services\AdditionalIssueService;
use App\Modules\Workshop\Services\IssueLineRules;
use App\Modules\Workshop\Services\JobSheetException;
use App\Modules\Workshop\Services\JobSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Job-sheet companions: customer-reported issues, additional jobs done,
 * observations & recommendations, and body-shop insurance details.
 */
class JobCardIssueController extends ApiController
{
    use ValidatesMediaUpload;

    public function __construct(
        protected JobSheetService $sheets,
        protected AdditionalIssueService $lines,
        protected IssueLineRules $lineRules,
    ) {}

    // ---- Additional Issues lines (customer complaints + technician findings) ----

    public function issues(JobCard $jobCard): JsonResponse
    {
        $issues = $jobCard->issues()
            ->with([
                'media',
                'sourceItem.media',
                'partRequests' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('id'),
                'resolvedBy:id,name',
                'reportedBy:id,name',
            ])
            ->get();

        return $this->success($issues->map(fn (JobCardIssue $issue) => $this->presentIssue($issue)));
    }

    public function storeIssue(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertOpen($jobCard);
        $data = $request->validate($this->issueRules(true) + [
            // Evidence of the faulty part, sent with a technician's new line
            'files' => 'sometimes|array|max:10',
            'files.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm',
                'max:' . max((int) config('workshop.job_sheets.max_video_kb'), (int) config('workshop.job_sheets.max_photo_kb')),
            ],
        ]);

        $issue = $this->lines->create($jobCard, $data, $request->user(), $request->file('files', []));

        return $this->created($this->presentIssue($issue->load(IssueLineRules::RELATIONS)), 'Line added');
    }

    public function updateIssue(Request $request, JobCard $jobCard, JobCardIssue $issue): JsonResponse
    {
        $this->assertOpen($jobCard);
        $data = $request->validate($this->issueRules(false));

        $issue = $this->lines->update($jobCard, $issue, $data, $request->user());

        return $this->success($this->presentIssue($issue->fresh([...IssueLineRules::RELATIONS, 'resolvedBy:id,name'])), 'Line updated');
    }

    public function destroyIssue(Request $request, JobCard $jobCard, JobCardIssue $issue): JsonResponse
    {
        $this->assertOpen($jobCard);
        $this->lines->delete($jobCard, $issue, $request->user());

        return $this->success(null, 'Line removed');
    }

    public function uploadIssueMedia(Request $request, JobCard $jobCard, JobCardIssue $issue): JsonResponse
    {
        $this->validateMediaUpload($request);
        $tag = $request->validate(['tag' => 'nullable|in:' . JobCardMedia::TAG_FITTED_PART])['tag'] ?? null;

        $this->lines->attachMedia(
            $jobCard, $issue, $request->file('file'), $request->user(), $tag,
            $request->file('poster'), $request->only(['duration_seconds', 'description', 'captured_at']),
        );

        return $this->created($this->presentIssue($issue->fresh(IssueLineRules::RELATIONS)), 'Uploaded');
    }

    public function deleteIssueMedia(Request $request, JobCard $jobCard, JobCardIssue $issue, JobCardMedia $media): JsonResponse
    {
        $this->lines->deleteMedia($jobCard, $issue, $media, $request->user());

        return $this->success($this->presentIssue($issue->fresh(IssueLineRules::RELATIONS)), 'Deleted');
    }

    private function presentIssue(JobCardIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'job_card_id' => $issue->job_card_id,
            'source' => $issue->source,
            'description' => $issue->description,
            'priority' => $issue->priority,
            'status' => $issue->status,
            'resolution_notes' => $issue->resolution_notes,
            'reported_by' => $issue->relationLoaded('reportedBy') ? $issue->reportedBy?->name : null,
            'resolved_by' => $issue->relationLoaded('resolvedBy') ? $issue->resolvedBy?->only(['id', 'name']) : null,
            'resolved_at' => $issue->resolved_at?->toIso8601String(),
            'media' => JobCardMediaResource::collection($issue->media)->resolve(),
            // Photos/videos taken on the flagged activity this line came from
            'activity_media' => $issue->relationLoaded('sourceItem') && $issue->sourceItem
                ? JobCardMediaResource::collection($issue->sourceItem->media)->resolve()
                : [],
            'part_requests' => $issue->partRequests->map(fn ($p) => [
                'id' => $p->id,
                'part_name' => $p->part_name,
                'part_number' => $p->part_number,
                'quantity' => (float) $p->quantity,
                'status' => $p->status,
            ])->values(),
            'needs_fitted_media' => $this->lineRules->hasPartsToFit($issue),
            'can_list_parts' => $this->lineRules->canListParts($issue),
            'parts_awaited' => $this->lineRules->partsAwaited($issue),
            'parts_arrived' => $this->lineRules->partsArrived($issue),
            'declined_by_customer' => $this->lineRules->declinedByCustomer($issue),
            'done_shortfall' => $this->lineRules->doneShortfall($issue),
        ];
    }

    // ---- Additional jobs done ----

    public function additionalJobs(JobCard $jobCard): JsonResponse
    {
        return $this->success($jobCard->additionalJobs()
            ->with(['doneBy:id,name', 'partRequests' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('id')])
            ->get());
    }

    public function storeAdditionalJob(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertOpen($jobCard);
        $this->assertTechnician($jobCard, $request);
        $data = $request->validate($this->additionalJobRules($jobCard, true));

        $job = $jobCard->additionalJobs()->create([
            ...$data,
            'cost' => $data['cost'] ?? 0,
            'done_by' => $request->user()->id,
        ]);

        return $this->created($job->load('doneBy:id,name'), 'Job added');
    }

    public function updateAdditionalJob(Request $request, JobCard $jobCard, JobCardAdditionalJob $additionalJob): JsonResponse
    {
        $this->assertOpen($jobCard);
        $this->assertTechnician($jobCard, $request);
        $additionalJob->update($request->validate($this->additionalJobRules($jobCard, false)));

        return $this->success($additionalJob->fresh('doneBy:id,name'), 'Job updated');
    }

    public function destroyAdditionalJob(Request $request, JobCard $jobCard, JobCardAdditionalJob $additionalJob): JsonResponse
    {
        $this->assertOpen($jobCard);
        $this->assertTechnician($jobCard, $request);
        $additionalJob->delete();

        return $this->success(null, 'Job removed');
    }

    // ---- Observations & recommendations ----

    public function observations(JobCard $jobCard): JsonResponse
    {
        return $this->success($jobCard->observations()->with(['createdBy:id,name', 'remark'])->get()
            ->map(fn (JobCardObservation $o) => [
                ...$o->toArray(),
                'remark_text' => $o->remark?->text($o->remark_days),
            ]));
    }

    public function storeObservation(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertOpen($jobCard);
        $this->assertTechnician($jobCard, $request);
        $data = $request->validate([
            'text' => 'required|string|max:1000',
            'customer_visible' => 'sometimes|boolean',
            'severity' => ['sometimes', 'nullable', Rule::in(JobCardObservation::SEVERITIES)],
            'remark_id' => ['sometimes', 'nullable', Rule::exists('observation_remarks', 'id')],
            'remark_days' => 'sometimes|nullable|integer|min:1|max:3650',
        ]);

        $observation = $jobCard->observations()->create([
            ...$data,
            'source' => JobCardObservation::SOURCE_MANUAL,
            'sort_order' => (int) $jobCard->observations()->max('sort_order') + 1,
            'created_by' => $request->user()->id,
        ]);

        return $this->created($observation, 'Observation added');
    }

    public function updateObservation(Request $request, JobCard $jobCard, JobCardObservation $observation): JsonResponse
    {
        $this->assertOpen($jobCard);
        $data = $request->validate([
            'text' => 'sometimes|required|string|max:1000',
            'customer_visible' => 'sometimes|boolean',
            'severity' => ['sometimes', 'nullable', Rule::in(JobCardObservation::SEVERITIES)],
            'remark_id' => ['sometimes', 'nullable', Rule::exists('observation_remarks', 'id')],
            'remark_days' => 'sometimes|nullable|integer|min:1|max:3650',
        ]);

        // The advisor chooses what the customer sees and the advice; the technician writes the observation
        if (!$this->sheets->isAssignedTechnician($jobCard, $request->user())) {
            if (array_diff(array_keys($data), ['customer_visible', 'remark_id', 'remark_days'])) {
                return $this->forbidden('View only: observations are written by the assigned technician.');
            }
        }

        // Activity-raised observations follow the activity's remarks
        if (isset($data['text']) && $observation->source !== JobCardObservation::SOURCE_MANUAL) {
            return $this->validationError(['text' => ['Edit the remarks on the activity to change this observation.']]);
        }

        $observation->update($data);

        return $this->success([
            ...$observation->fresh('remark')->toArray(),
            'remark_text' => $observation->remark?->text($observation->remark_days),
        ], 'Observation updated');
    }

    public function destroyObservation(Request $request, JobCard $jobCard, JobCardObservation $observation): JsonResponse
    {
        $this->assertOpen($jobCard);
        $this->assertTechnician($jobCard, $request);

        if ($observation->source !== JobCardObservation::SOURCE_MANUAL) {
            return $this->validationError(['observation' => ['Clear the ⚠ flag on the activity to remove this observation.']]);
        }

        $observation->delete();

        return $this->success(null, 'Observation removed');
    }

    // ---- Body-shop insurance details ----

    public function insurance(JobCard $jobCard): JsonResponse
    {
        return $this->success($jobCard->insuranceDetail);
    }

    public function updateInsurance(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'insurance_company' => 'nullable|string|max:255',
            'claim_no' => 'nullable|string|max:100',
            'survey_date' => 'nullable|date',
            'surveyor_name' => 'nullable|string|max:255',
            'surveyor_mobile' => 'nullable|string|max:20',
        ]);

        $detail = $jobCard->insuranceDetail()->updateOrCreate([], $data);

        return $this->success($detail, 'Insurance details saved');
    }

    private function issueRules(bool $creating): array
    {
        return [
            'description' => ($creating ? 'required' : 'sometimes|required') . '|string|max:1000',
            'priority' => ['sometimes', Rule::in(JobCardIssue::PRIORITIES)],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(JobCardIssue::STATUSES)],
            'resolution_notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * "Submit for Report": the technician sends his observations to the advisor for the report.
     */
    public function submitObservations(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertTechnician($jobCard, $request);

        $count = $jobCard->observations()->count();
        if (!$count) {
            return $this->validationError(['observations' => ['Add an observation first.']]);
        }

        $advisor = $jobCard->service_advisor_id ? User::find($jobCard->service_advisor_id) : null;
        $advisor?->notify(StaffAlert::observationsSubmitted($jobCard, $request->user()->name, $count));

        return $this->success(['submitted' => $count], 'Sent to the service advisor for the report');
    }

    /**
     * Remarks for observations (master list).
     */
    public function remarks(): JsonResponse
    {
        return $this->success(ObservationRemark::where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'label', 'needs_days']));
    }

    private function additionalJobRules(JobCard $jobCard, bool $creating): array
    {
        return [
            'description' => ($creating ? 'required' : 'sometimes|required') . '|string|max:1000',
            'parts_used' => 'nullable|string|max:1000',
            'cost' => 'nullable|numeric|min:0',
            'job_card_issue_id' => ['nullable', Rule::exists('job_card_issues', 'id')->where('job_card_id', $jobCard->id)],
        ];
    }

    /**
     * Additional jobs and observations are the assigned technician's; others view them.
     */
    private function assertTechnician(JobCard $jobCard, Request $request): void
    {
        if (!$this->sheets->isAssignedTechnician($jobCard, $request->user())) {
            throw new JobSheetException(
                $jobCard->technician_id
                    ? 'View only: additional jobs and observations are recorded by the assigned technician.'
                    : 'Assign a technician first: the technician records additional jobs and observations.',
                [],
                403
            );
        }
    }

    private function assertOpen(JobCard $jobCard): void
    {
        if ($jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }
    }
}
