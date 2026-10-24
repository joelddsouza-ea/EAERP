<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Http\Controllers\Concerns\ValidatesMediaUpload;
use App\Modules\Workshop\Http\Resources\JobCardMediaResource;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Services\IssueLineRules;
use App\Modules\Workshop\Services\JobSheetService;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Walk-in intake: the advisor's trial drive and the inventory photos/videos.
 * Both can change only until work starts.
 */
class IntakeController extends ApiController
{
    use ValidatesMediaUpload;

    private const EDITABLE = [
        JobCardStatus::PickupScheduled->value,
        JobCardStatus::PickupEnRoute->value,
        JobCardStatus::VehicleCollected->value,
        JobCardStatus::IntakePending->value,
        JobCardStatus::IntakeInProgress->value,
    ];

    public function __construct(protected JobSheetService $sheets) {}

    public function show(JobCard $jobCard): JsonResponse
    {
        return $this->success($this->present($jobCard));
    }

    public function updateTrial(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertEditable($jobCard);

        $data = $request->validate([
            'status' => 'required|in:done,skipped',
            'notes' => 'nullable|required_if:status,skipped|string|max:2000',
        ], [
            'notes.required_if' => 'Say why the trial drive was skipped (e.g. car not drivable).',
        ]);

        $jobCard->update([
            'intake_trial_status' => $data['status'],
            'intake_trial_notes' => $data['notes'] ?? null,
            'intake_trial_by' => $request->user()->id,
            'intake_trial_at' => now(),
        ]);

        // What the trial drive found goes on the technician's Additional Issues (one line, kept in step)
        $notes = $data['status'] === 'done' ? trim((string) ($data['notes'] ?? '')) : '';
        $line = JobCardIssue::where('job_card_id', $jobCard->id)->where('source', JobCardIssue::SOURCE_TRIAL)->first();
        if ($notes !== '') {
            $line
                ? $line->update(['description' => $notes])
                : JobCardIssue::create(['job_card_id' => $jobCard->id, 'source' => JobCardIssue::SOURCE_TRIAL, 'description' => $notes,
                    'priority' => 'normal', 'status' => 'open', 'reported_by' => $request->user()->id]);
        } elseif ($line && $line->status === 'open' && !$line->media()->exists()) {
            $line->delete();
        }

        return $this->success($this->present($jobCard->fresh()), 'Trial drive recorded');
    }

    public function uploadMedia(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertEditable($jobCard);
        $this->validateMediaUpload($request);

        $media = $this->sheets->storeMedia(
            $jobCard,
            $request->file('file'),
            'job_card',
            $jobCard->id,
            'intake',
            "Intake inventory — {$jobCard->job_number}",
            $request->user(),
            $request->file('poster'),
            $request->only(['duration_seconds', 'description', 'captured_at']),
        );

        return $this->created([
            'media' => new JobCardMediaResource($media),
            'intake' => $this->present($jobCard),
        ], 'Uploaded');
    }

    public function deleteMedia(Request $request, JobCard $jobCard, JobCardMedia $media): JsonResponse
    {
        $this->assertEditable($jobCard);

        if ($media->attachable_type !== 'job_card' || $media->attachable_id !== $jobCard->id) {
            return $this->notFound('Media not found in this intake.');
        }

        $user = $request->user();
        if ($media->uploaded_by !== $user->id && !$user->hasPermission('workshop.job-sheets.delete-any-media')) {
            return $this->forbidden('You can only delete media you uploaded.');
        }

        $media->delete();

        return $this->success($this->present($jobCard), 'Deleted');
    }

    private function present(JobCard $jobCard): array
    {
        $jobCard->loadMissing('intakeTrialBy:id,name');
        $media = $jobCard->intakeMedia()->orderBy('id')->get();
        $counts = IntakeCaptured::mediaCounts($jobCard);

        return [
            'editable' => in_array($jobCard->status, self::EDITABLE, true),
            'trial' => [
                'status' => $jobCard->intake_trial_status,
                'notes' => $jobCard->intake_trial_notes,
                'by' => $jobCard->intakeTrialBy?->name,
                'at' => $jobCard->intake_trial_at?->toIso8601String(),
            ],
            'km_reading' => $jobCard->km_reading,
            'fuel_level' => $jobCard->fuel_level,
            'requirements' => [
                'min_photos' => IntakeCaptured::minPhotos(),
                'min_videos' => IntakeCaptured::minVideos(),
            ],
            'counts' => $counts,
            'media' => JobCardMediaResource::collection($media),
        ];
    }

    private function assertEditable(JobCard $jobCard): void
    {
        abort_unless(
            in_array($jobCard->status, self::EDITABLE, true),
            422,
            'Intake can no longer change: work on the car has started.'
        );
    }

    /**
     * Owner/manager settings: minimum intake photos/videos, and the photos/videos an
     * Additional Issues line needs (problem evidence, fitted new part).
     */
    public function updateRequirements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'min_photos' => 'sometimes|required|integer|min:0|max:30',
            'min_videos' => 'sometimes|required|integer|min:0|max:10',
            'issue_min_problem_media' => 'sometimes|required|integer|min:0|max:10',
            'issue_min_fitted_media' => 'sometimes|required|integer|min:0|max:10',
        ]);

        $keys = [
            'min_photos' => 'intake.min_photos',
            'min_videos' => 'intake.min_videos',
            'issue_min_problem_media' => 'issues.min_problem_media',
            'issue_min_fitted_media' => 'issues.min_fitted_media',
        ];
        foreach ($data as $field => $value) {
            WorkshopSettings::set($keys[$field], (int) $value);
        }

        return $this->success($this->settings(), 'Settings saved');
    }

    public function requirements(): JsonResponse
    {
        return $this->success($this->settings());
    }

    private function settings(): array
    {
        return [
            'min_photos' => IntakeCaptured::minPhotos(),
            'min_videos' => IntakeCaptured::minVideos(),
            'issue_min_problem_media' => IssueLineRules::minProblemMedia(),
            'issue_min_fitted_media' => IssueLineRules::minFittedMedia(),
        ];
    }
}
