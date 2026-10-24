<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Billing\Models\GatePass;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Workshop\Enums\JobCardStatus as S;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;
use App\Modules\Workshop\Workflow\JobCardWorkflow;

/**
 * Moves jobs through their stages by themselves, from what has happened on the job
 * (intake, estimate answers, parts, job sheets, checklist, bill, payment, gate pass).
 *
 * sync() is called after anything that can change those facts. A stage switched to
 * manual in the Job stages master is never entered by the system; the workflow
 * guards still apply to every automatic move.
 */
class JobLifecycleService
{
    /** Safety stop: no job can move through more stages than this in one go */
    private const MAX_STEPS = 8;

    public function __construct(
        protected JobCardStatusService $statuses,
        protected JobCardWorkflow $workflow,
    ) {}

    /**
     * @return string[] the statuses the job moved into
     */
    public function sync(JobCard $jobCard, string $via = 'auto'): array
    {
        $moved = [];

        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $jobCard->refresh();

            if ($jobCard->status === S::Invoiced->value) {
                app(BillingService::class)->issueGatePassIfPaid($jobCard);
            }

            $next = $this->nextStatus($jobCard);

            if (!$next || !WorkflowStage::isAuto($next)) {
                break;
            }
            // Sent back to work (trial drive / QC failed): people move it on again when the rework is done
            if ($jobCard->status === S::WorkInProgress->value
                && $this->workflow->isForward($jobCard->status, $next)
                && $this->workflow->enteredBackwards($jobCard)) {
                break;
            }
            if (!$this->statuses->changeBySystem($jobCard, $next, $via, 'Moved automatically.')) {
                break;
            }

            $moved[] = $next;
        }

        return $moved;
    }

    /**
     * Where the facts say the job should be next, or null to stay.
     */
    public function nextStatus(JobCard $jobCard): ?string
    {
        return match ($jobCard->status) {
            S::IntakePending->value => $this->intakeStarted($jobCard) ? S::IntakeInProgress->value : null,

            S::IntakeInProgress->value => $this->approvalPending($jobCard)
                ? S::AwaitingApproval->value
                : S::WorkInProgress->value, // guards: intake complete + technician assigned

            S::AwaitingApproval->value => $this->approvalPending($jobCard) ? null : S::WorkInProgress->value,

            S::WorkInProgress->value => match (true) {
                $this->approvalPending($jobCard) => S::AwaitingApproval->value,
                $this->partsAwaited($jobCard) => S::AwaitingParts->value,
                // Trial drive and wash are sections of the job sheet: straight to the final QC
                $this->workSheetsSubmitted($jobCard) => S::QcPending->value,
                default => null,
            },

            S::AwaitingParts->value => $this->partsAwaited($jobCard) ? null : S::WorkInProgress->value,

            // Stages no longer used (trial and wash are on the job sheet): jobs left there move on
            S::PostWorkTrial->value, S::WashPending->value => S::QcPending->value,

            // Final QC after the wash: the pre-delivery checklist
            S::QcPending->value => $this->preDeliverySubmitted($jobCard) ? S::ReadyForDelivery->value : null,

            S::ReadyForDelivery->value => app(BillingService::class)->payableBill($jobCard) ? S::Invoiced->value : null,

            S::Invoiced->value => $this->gatePass($jobCard) ? S::GatePassGenerated->value : null,

            // Out of the gate, and handed over at the customer's place when there is a drop / home visit
            S::GatePassGenerated->value => $this->gatePass($jobCard)?->vehicle_out_at && !$this->handoverPending($jobCard)
                ? S::Delivered->value
                : null,

            default => null,
        };
    }

    /**
     * What the current stage is waiting for (from the master), for the job card screen.
     */
    public function waitingFor(JobCard $jobCard): ?string
    {
        return WorkflowStage::for($jobCard->status)?->waiting_for;
    }

    public function intakeStarted(JobCard $jobCard): bool
    {
        if ($jobCard->intake_trial_status !== null) {
            return true;
        }

        $counts = IntakeCaptured::mediaCounts($jobCard);

        return $counts['photo'] + $counts['video'] > 0;
    }

    public function approvalPending(JobCard $jobCard): bool
    {
        $estimate = JobCardEstimate::where('job_card_id', $jobCard->id)
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->latest('version')
            ->first();

        return $estimate !== null && $estimate->lines()->where('approval_status', Line::PENDING)->exists();
    }

    /**
     * Parts the customer approved that have not arrived yet.
     */
    public function partsAwaited(JobCard $jobCard): bool
    {
        return SparePartRequest::withoutGlobalScopes()
            ->where('job_card_id', $jobCard->id)
            ->whereIn('status', [SparePartRequest::APPROVED, SparePartRequest::ORDERED])
            ->exists();
    }

    public function workSheetsSubmitted(JobCard $jobCard): bool
    {
        $sheets = $jobCard->checklists()->whereIn('sheet_kind', ChecklistTemplate::WORK_SHEET_KINDS)->get(['id', 'status']);

        return $sheets->isNotEmpty() && $sheets->every(fn (JobCardChecklist $s) => $s->isCompleted());
    }

    public function preDeliverySubmitted(JobCard $jobCard): bool
    {
        // A send-back from QC reopens the checklist, so a submitted one is always for this round
        $sheets = $jobCard->checklists()->where('sheet_kind', ChecklistTemplate::KIND_PRE_DELIVERY)->get(['id', 'status']);

        return $sheets->isNotEmpty() && $sheets->every(fn (JobCardChecklist $s) => $s->isCompleted());
    }

    /**
     * A drop or home visit not yet closed by the driver / technician.
     */
    public function handoverPending(JobCard $jobCard): bool
    {
        return JobTrip::where('job_card_id', $jobCard->id)
            ->whereIn('kind', [JobTrip::KIND_DROP, JobTrip::KIND_HOME_VISIT])
            ->whereIn('status', [JobTrip::EN_ROUTE, JobTrip::ARRIVED])
            ->exists();
    }

    protected function gatePass(JobCard $jobCard): ?GatePass
    {
        return GatePass::where('job_card_id', $jobCard->id)->active()->latest('id')->first();
    }
}
