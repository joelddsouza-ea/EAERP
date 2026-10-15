<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobSheetException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * After the work: the advisor records the trial drive and "washing done". The job then
 * moves on by itself (job.lifecycle): trial OK → wash, issues → back to work, washed → final QC.
 */
class PostServiceController extends ApiController
{
    public function trial(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertStatus($jobCard, JobCardStatus::PostWorkTrial, 'The trial drive is recorded once the work is done (Trial Drive stage).');

        $data = $request->validate([
            'result' => 'required|in:ok,issues',
            'notes' => 'nullable|required_if:result,issues|string|max:2000',
        ], ['notes.required_if' => 'Say what the trial drive found, for the technician.']);

        $jobCard->update([
            'post_trial_result' => $data['result'],
            'post_trial_notes' => $data['notes'] ?? null,
            'post_trial_by' => $request->user()->id,
            'post_trial_at' => now(),
        ]);

        return $this->success($this->present($jobCard), $data['result'] === 'ok' ? 'Trial drive OK' : 'Sent back to work');
    }

    public function wash(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertStatus($jobCard, JobCardStatus::WashPending, 'Washing is recorded at the Wash Pending stage.');

        $jobCard->update(['wash_done_by' => $request->user()->id, 'wash_done_at' => now()]);

        return $this->success($this->present($jobCard), 'Washing done');
    }

    private function assertStatus(JobCard $jobCard, JobCardStatus $status, string $message): void
    {
        if ($jobCard->status !== $status->value) {
            throw new JobSheetException($message);
        }
    }

    private function present(JobCard $jobCard): array
    {
        return $jobCard->only(['id', 'status', 'post_trial_result', 'post_trial_notes', 'post_trial_at', 'wash_done_at']);
    }
}
