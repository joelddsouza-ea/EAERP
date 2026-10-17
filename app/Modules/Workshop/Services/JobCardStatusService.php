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
use App\Modules\Workshop\Models\WorkflowStage;
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
        protected CustomerMessageService $customerMessages,
        protected CustomerGalleryService $gallery,
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
        array $meta = [],
    ): JobCard {
        $fromStatus = $jobCard->status;
        $edge = $this->workflow->edge($fromStatus, $toStatus);

        if (!$edge) {
            throw new TransitionBlockedException($this->label($fromStatus) . ' cannot move to ' . $this->label($toStatus) . '.');
        }

        if ($edge['trip'] && empty($meta['trip_id'])) {
            throw new TransitionBlockedException('This step is recorded on the pickup / home visit (trip) screen.');
        }

        $manual = $this->workflow->isManualCorrection($jobCard, $toStatus);

        if (!$this->workflow->mayTake($jobCard, $user, $toStatus, $edge)) {
            throw new TransitionBlockedException(
                $manual
                    ? $this->label($toStatus) . ' is reached automatically' . $this->waitingFor($fromStatus) . ' Only an owner or manager can move the job by hand.'
                    : 'You do not have permission to move this job to ' . $this->label($toStatus) . '.',
                [],
                403
            );
        }

        $notes = $notes !== null ? trim($notes) : null;

        if ($manual && !$notes) {
            throw new TransitionBlockedException('Give a reason for moving the job by hand (it is logged).');
        }

        if ($edge['requires_notes'] && !$notes) {
            throw new TransitionBlockedException('A note is required to move this job to ' . $this->label($toStatus) . '.');
        }

        if ($manual) {
            $meta += ['manual' => true];
        }

        $failures = $this->workflow->failedGuards($jobCard, $edge);

        if ($failures) {
            if (!$override) {
                throw new TransitionBlockedException('This job cannot move to ' . $this->label($toStatus) . ' yet.', $failures);
            }
            if (array_filter($failures, fn (GuardFailure $f) => in_array($f->code, JobCardWorkflow::HARD_GUARDS, true))) {
                throw new TransitionBlockedException('This check cannot be overridden here.', $failures);
            }
            if (!$user->hasPermission('workshop.job-cards.override-guards')) {
                throw new TransitionBlockedException('You do not have permission to override these checks.', $failures, 403);
            }
            if (!$notes) {
                throw new TransitionBlockedException('A note is required when overriding checks.', $failures);
            }
            $meta += [
                'override' => true,
                'overridden_guards' => array_map(fn (GuardFailure $f) => $f->toArray(), $failures),
            ];
        }

        $this->apply($jobCard, $fromStatus, $toStatus, $user, $notes, $meta ?: null);

        return $jobCard->fresh();
    }

    /**
     * A change the system makes on its own (e.g. back to work once the customer has
     * answered every estimate line). Only taken when the edge exists and its guards
     * pass; there is no user, so no permission check. Returns whether it moved.
     */
    public function changeBySystem(JobCard $jobCard, string $toStatus, string $via, ?string $notes = null): bool
    {
        $fromStatus = $jobCard->status;
        $edge = $this->workflow->edge($fromStatus, $toStatus);

        if (!$edge || $this->workflow->failedGuards($jobCard, $edge)) {
            return false;
        }

        $this->apply($jobCard, $fromStatus, $toStatus, null, $notes, ['via' => $via]);

        return true;
    }

    protected function apply(JobCard $jobCard, string $fromStatus, string $toStatus, ?User $user, ?string $notes, ?array $meta): void
    {
        DB::transaction(function () use ($jobCard, $fromStatus, $toStatus, $user, $notes, $meta) {
            $jobCard->status = $toStatus;
            $jobCard->status_changed_at = now();

            match ($toStatus) {
                JobCardStatus::IntakeInProgress->value => $jobCard->time_in ??= now(),
                // First start only; resuming after parts/QA must not reset it
                JobCardStatus::WorkInProgress->value => $jobCard->work_started_at ??= now(),
                // Each round of trial drive / wash is recorded afresh
                JobCardStatus::PostWorkTrial->value => $jobCard->forceFill([
                    'work_completed_at' => now(),
                    'post_trial_result' => null, 'post_trial_notes' => null, 'post_trial_by' => null, 'post_trial_at' => null,
                ]),
                JobCardStatus::WashPending->value => $jobCard->forceFill(['wash_done_by' => null, 'wash_done_at' => null]),
                JobCardStatus::ReadyForDelivery->value => $jobCard->qc_completed_at = now(),
                JobCardStatus::Delivered->value => $jobCard->time_out = now(),
                JobCardStatus::Cancelled->value => $jobCard->cancel_reason = $notes,
                default => null,
            };

            $jobCard->save();

            if ($user && $fromStatus === JobCardStatus::WorkInProgress->value && $toStatus === JobCardStatus::PostWorkTrial->value) {
                $this->completeWorkSheets($jobCard, $user, $meta);
            }

            // Sent back from final QC (or later): the QC checklist is done again next time
            if (in_array($fromStatus, [JobCardStatus::QcPending->value, JobCardStatus::ReadyForDelivery->value], true)
                && in_array($toStatus, [JobCardStatus::WashPending->value, JobCardStatus::WorkInProgress->value], true)) {
                $jobCard->checklists()
                    ->where('sheet_kind', ChecklistTemplate::KIND_PRE_DELIVERY)
                    ->where('status', JobCardChecklist::STATUS_COMPLETED)
                    ->update(['status' => JobCardChecklist::STATUS_IN_PROGRESS, 'completed_at' => null, 'completed_by' => null]);
            }

            JobCardStatusHistory::create([
                'job_card_id' => $jobCard->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'notes' => $notes,
                'meta' => $meta,
                'changed_by' => $user?->id,
            ]);

            // Pickup / drop / home-visit trips follow the job (cancelled, car came in, collected at the workshop)
            app(TripService::class)->onStatusChanged($jobCard, $fromStatus, $toStatus, $user, $notes);

            DB::afterCommit(fn () => $this->afterTransition($jobCard, $toStatus, $user));
        });
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

    protected function afterTransition(JobCard $jobCard, string $toStatus, ?User $user): void
    {
        // The Job stages master decides; config is the fallback for stages without a row
        $stage = WorkflowStage::for($toStatus);
        $mode = $stage
            ? ($stage->notify_customer === WorkflowStage::NOTIFY_OFF ? null : $stage->notify_customer)
            : config("workshop.workflow.notify_customer.{$toStatus}");

        $jobCard->loadMissing('customer');
        $sendStage = $mode && ($mode === 'always' || $this->isFirstEntry($jobCard, $toStatus));

        // New photos/videos since the customer was last told, if this stage tells them
        $gallery = $stage?->gallery_update && $jobCard->customer ? $this->gallery->noticeSince($jobCard->fresh()) : null;

        if ($sendStage && $jobCard->customer) {
            $this->notificationService->notifyJobCardStatus($jobCard->customer, $jobCard->job_number, $toStatus);
            $message = $this->customerMessages->prepareForStatus($jobCard, $toStatus, $user, $gallery);
            if ($message && $gallery) {
                $this->gallery->markNotified($jobCard);
                $gallery = null;
            }
        }

        if ($gallery) {
            $this->customerMessages->prepare($jobCard, 'gallery_update', ['gallery' => $gallery], $user);
            $this->gallery->markNotified($jobCard);
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

    /**
     * " when: <what the stage waits for>." from the Job stages master.
     */
    private function waitingFor(string $status): string
    {
        $text = WorkflowStage::for($status)?->waiting_for;

        return $text ? ' when: ' . rtrim(lcfirst($text), '.') . '.' : '.';
    }

    private function label(string $status): string
    {
        return JobCardStatus::tryFrom($status)?->label() ?? $status;
    }
}
