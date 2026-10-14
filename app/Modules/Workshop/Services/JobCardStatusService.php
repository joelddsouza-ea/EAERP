<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Core\Services\NotificationService;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\FollowUpCall;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Workflow\GuardFailure;
use App\Modules\Workshop\Workflow\JobCardWorkflow;
use App\Modules\Workshop\Workflow\TransitionBlockedException;
use Illuminate\Support\Facades\DB;

/**
 * Applies job card status changes through the workflow: edge, permission, notes and
 * guard checks, timestamps, history, and customer notifications.
 */
class JobCardStatusService
{
    public function __construct(
        protected JobCardWorkflow $workflow,
        protected NotificationService $notificationService,
    ) {}

    /**
     * @throws TransitionBlockedException
     */
    public function change(
        JobCard $jobCard,
        string $toStatus,
        User $user,
        ?string $notes = null,
        bool $override = false,
    ): JobCard {
        $fromStatus = $jobCard->status;
        $edge = $this->workflow->edge($fromStatus, $toStatus);

        if (!$edge) {
            throw new TransitionBlockedException($this->label($fromStatus) . ' cannot move to ' . $this->label($toStatus) . '.');
        }

        if (!$user->hasAnyPermission($edge['perms'])) {
            throw new TransitionBlockedException('You do not have permission to move this job to ' . $this->label($toStatus) . '.', [], 403);
        }

        $notes = $notes !== null ? trim($notes) : null;

        if ($edge['requires_notes'] && !$notes) {
            throw new TransitionBlockedException('A note is required to move this job to ' . $this->label($toStatus) . '.');
        }

        $failures = $this->workflow->failedGuards($jobCard, $edge);
        $meta = null;

        if ($failures) {
            if (!$override) {
                throw new TransitionBlockedException('This job cannot move to ' . $this->label($toStatus) . ' yet.', $failures);
            }
            if (!$user->hasPermission('workshop.job-cards.override-guards')) {
                throw new TransitionBlockedException('You do not have permission to override these checks.', $failures, 403);
            }
            if (!$notes) {
                throw new TransitionBlockedException('A note is required when overriding checks.', $failures);
            }
            $meta = [
                'override' => true,
                'overridden_guards' => array_map(fn (GuardFailure $f) => $f->toArray(), $failures),
            ];
        }

        DB::transaction(function () use ($jobCard, $fromStatus, $toStatus, $user, $notes, $meta) {
            $jobCard->status = $toStatus;
            $jobCard->status_changed_at = now();

            match ($toStatus) {
                JobCardStatus::IntakeInProgress->value => $jobCard->time_in ??= now(),
                // First start only; resuming after parts/QA must not reset it
                JobCardStatus::WorkInProgress->value => $jobCard->work_started_at ??= now(),
                JobCardStatus::PostWorkTrial->value => $jobCard->work_completed_at = now(),
                JobCardStatus::ReadyForDelivery->value => $jobCard->qc_completed_at = now(),
                JobCardStatus::Delivered->value => $jobCard->time_out = now(),
                JobCardStatus::Cancelled->value => $jobCard->cancel_reason = $notes,
                default => null,
            };

            $jobCard->save();

            if ($fromStatus === JobCardStatus::WorkInProgress->value && $toStatus === JobCardStatus::PostWorkTrial->value) {
                $this->completeWorkSheets($jobCard, $user, $meta);
            }

            JobCardStatusHistory::create([
                'job_card_id' => $jobCard->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
                'meta' => $meta,
                'changed_by' => $user->id,
            ]);

            DB::afterCommit(fn () => $this->afterTransition($jobCard, $toStatus));
        });

        return $jobCard->fresh();
    }

    /**
     * Activity sheets are locked once the car leaves the technician for the trial drive.
     */
    protected function completeWorkSheets(JobCard $jobCard, User $user, ?array $meta): void
    {
        $jobCard->checklists()
            ->whereIn('sheet_kind', ChecklistTemplate::WORK_SHEET_KINDS)
            ->where('status', '!=', JobCardChecklist::STATUS_COMPLETED)
            ->get()
            ->each(fn (JobCardChecklist $sheet) => $sheet->update([
                'status' => JobCardChecklist::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_by' => $user->id,
                'override_meta' => $meta,
            ]));
    }

    protected function afterTransition(JobCard $jobCard, string $toStatus): void
    {
        $mode = config("workshop.workflow.notify_customer.{$toStatus}");

        if ($mode && ($mode === 'always' || $this->isFirstEntry($jobCard, $toStatus))) {
            $jobCard->loadMissing('customer');
            if ($jobCard->customer) {
                $this->notificationService->notifyJobCardStatus($jobCard->customer, $jobCard->job_number, $toStatus);
            }
        }

        if ($toStatus === JobCardStatus::Delivered->value) {
            $this->scheduleFollowUpCalls($jobCard);
        }
    }

    protected function isFirstEntry(JobCard $jobCard, string $status): bool
    {
        return JobCardStatusHistory::where('job_card_id', $jobCard->id)
            ->where('to_status', $status)
            ->count() <= 1;
    }

    /**
     * Schedule follow-up calls after delivery.
     */
    protected function scheduleFollowUpCalls(JobCard $jobCard): void
    {
        FollowUpCall::create([
            'job_card_id' => $jobCard->id,
            'customer_id' => $jobCard->customer_id,
            'call_type' => 'courtesy_2day',
            'status' => 'scheduled',
            'scheduled_date' => now()->addDays(2)->toDateString(),
        ]);

        FollowUpCall::create([
            'job_card_id' => $jobCard->id,
            'customer_id' => $jobCard->customer_id,
            'call_type' => 'feedback',
            'status' => 'scheduled',
            'scheduled_date' => now()->addDays(7)->toDateString(),
        ]);
    }

    private function label(string $status): string
    {
        return JobCardStatus::tryFrom($status)?->label() ?? $status;
    }
}
