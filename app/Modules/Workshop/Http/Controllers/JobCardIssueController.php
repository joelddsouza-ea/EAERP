<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Services\JobSheetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Job-sheet companions: customer-reported issues, additional jobs done,
 * observations & recommendations, and body-shop insurance details.
 */
class JobCardIssueController extends ApiController
{
    // ---- Customer-reported issues ----

    public function issues(JobCard $jobCard): JsonResponse
    {
        return $this->success($jobCard->issues()->with(['additionalJobs', 'resolvedBy:id,name'])->get());
    }

    public function storeIssue(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertOpen($jobCard);
        $data = $request->validate($this->issueRules(true));

        $issue = $jobCard->issues()->create([
            ...$data,
            'priority' => $data['priority'] ?? 'normal',
            'status' => $data['status'] ?? 'open',
            'reported_by' => $request->user()->id,
            'sort_order' => (int) $jobCard->issues()->max('sort_order') + 1,
        ]);

        return $this->created($issue->load('additionalJobs'), 'Issue added');
    }

    public function updateIssue(Request $request, JobCard $jobCard, JobCardIssue $issue): JsonResponse
    {
        $this->assertOpen($jobCard);
        $data = $request->validate($this->issueRules(false));

        $status = $data['status'] ?? $issue->status;
        if (in_array($status, ['resolved', 'deferred'], true) && !($data['resolution_notes'] ?? $issue->resolution_notes)) {
            return $this->validationError(['resolution_notes' => ['Add a note when resolving or deferring an issue.']]);
        }

        if (isset($data['status']) && $data['status'] !== $issue->status) {
            $closing = in_array($data['status'], ['resolved', 'deferred'], true);
            $data['resolved_by'] = $closing ? $request->user()->id : null;
            $data['resolved_at'] = $closing ? now() : null;
        }

        $issue->update($data);

        return $this->success($issue->fresh(['additionalJobs', 'resolvedBy:id,name']), 'Issue updated');
    }

    public function destroyIssue(JobCard $jobCard, JobCardIssue $issue): JsonResponse
    {
        $this->assertOpen($jobCard);
        $issue->delete();

        return $this->success(null, 'Issue removed');
    }

    // ---- Additional jobs done ----

    public function additionalJobs(JobCard $jobCard): JsonResponse
    {
        return $this->success($jobCard->additionalJobs()->with('doneBy:id,name')->get());
    }

    public function storeAdditionalJob(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertOpen($jobCard);
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
        $additionalJob->update($request->validate($this->additionalJobRules($jobCard, false)));

        return $this->success($additionalJob->fresh('doneBy:id,name'), 'Job updated');
    }

    public function destroyAdditionalJob(JobCard $jobCard, JobCardAdditionalJob $additionalJob): JsonResponse
    {
        $this->assertOpen($jobCard);
        $additionalJob->delete();

        return $this->success(null, 'Job removed');
    }

    // ---- Observations & recommendations ----

    public function observations(JobCard $jobCard): JsonResponse
    {
        return $this->success($jobCard->observations()->with('createdBy:id,name')->get());
    }

    public function storeObservation(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertOpen($jobCard);
        $data = $request->validate([
            'text' => 'required|string|max:1000',
            'customer_visible' => 'sometimes|boolean',
            'severity' => ['sometimes', 'nullable', Rule::in(JobCardObservation::SEVERITIES)],
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
        ]);

        // Activity-raised observations follow the activity's remarks
        if (isset($data['text']) && $observation->source !== JobCardObservation::SOURCE_MANUAL) {
            return $this->validationError(['text' => ['Edit the remarks on the activity to change this observation.']]);
        }

        $observation->update($data);

        return $this->success($observation, 'Observation updated');
    }

    public function destroyObservation(JobCard $jobCard, JobCardObservation $observation): JsonResponse
    {
        $this->assertOpen($jobCard);

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
            'status' => ['sometimes', Rule::in(JobCardIssue::STATUSES)],
            'resolution_notes' => 'nullable|string|max:1000',
        ];
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

    private function assertOpen(JobCard $jobCard): void
    {
        if ($jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }
    }
}
