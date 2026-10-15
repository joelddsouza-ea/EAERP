<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateDecision;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobCardItem;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Support\Facades\DB;

/**
 * Approve / decline decisions on the additional lines of the estimate the customer
 * currently sees. Every change is written to an append-only audit table. Decisions
 * an advisor records on the customer's behalf need a note, and the customer is
 * always sent a message listing them.
 */
class EstimateDecisionService
{
    public function __construct(
        protected EstimateCalculator $calculator,
        protected CustomerMessageService $messages,
        protected JobCardStatusService $statusService,
        protected PartsQuoteService $parts,
        protected ObservationService $observations,
    ) {}

    /**
     * @param array<string, string> $decisions line_key => approved|declined
     * @return array<int, array{line_key: string, description: string, from: string, to: string}> the changes made
     */
    public function decide(
        JobCard $jobCard,
        array $decisions,
        string $via,
        ?User $user = null,
        ?string $note = null,
        ?string $ip = null,
    ): array {
        $estimate = $this->currentEstimate($jobCard);
        $this->assertOpen($jobCard);

        $note = $note !== null ? trim($note) : null;
        if ($via === Line::VIA_ADVISOR && !$note) {
            throw new EstimateException('Add a note saying how the customer gave this decision (e.g. phone call).', [
                'note' => ['A note is required when recording a decision on the customer\'s behalf.'],
            ]);
        }

        $lines = $estimate->lines->keyBy('line_key');

        foreach ($decisions as $key => $decision) {
            $line = $lines->get($key);
            if (!$line) {
                throw new EstimateException('That item is not on the current estimate.', [], 404);
            }
            if (!$line->isAdditional()) {
                throw new EstimateException('Items included in the plan do not need approval.');
            }
            if (!in_array($decision, [Line::APPROVED, Line::DECLINED], true)) {
                throw new EstimateException('A decision must be approved or declined.');
            }
        }

        $changes = [];

        DB::transaction(function () use ($jobCard, $estimate, $lines, $decisions, $via, $user, $note, $ip, &$changes) {
            $audit = [];

            foreach ($decisions as $key => $decision) {
                $line = $lines->get($key);
                if ($line->approval_status === $decision) {
                    continue;
                }

                $changes[] = [
                    'line_key' => $key,
                    'description' => $line->description,
                    'amount' => (float) $line->amount,
                    'from' => $line->approval_status,
                    'to' => $decision,
                ];

                $audit[] = JobCardEstimateDecision::create([
                    'job_card_id' => $jobCard->id,
                    'estimate_id' => $estimate->id,
                    'line_key' => $key,
                    'description' => $line->description,
                    'from_status' => $line->approval_status,
                    'to_status' => $decision,
                    'decided_via' => $via,
                    'user_id' => $user?->id,
                    'note' => $note,
                    'ip_address' => $ip,
                ]);

                $line->update([
                    'approval_status' => $decision,
                    'decided_via' => $via,
                    'decided_by' => $user?->id,
                    'decided_at' => now(),
                ]);
            }

            if (!$changes) {
                return;
            }

            $estimate->setRelation('lines', $estimate->lines()->get());
            if (!$this->hasPending($estimate)) {
                $estimate->responded_at = now();
            }
            $this->calculator->recalculate($estimate);
            $this->syncJobCard($jobCard, $estimate);
            $this->parts->syncFromEstimate($jobCard, $estimate);
            // Declined lines go to the Observations list ("Customer not approved")
            $this->observations->syncDeclinedLines($jobCard, $estimate);

            if ($via === Line::VIA_ADVISOR) {
                $message = $this->messages->prepare($jobCard, 'decision_recorded', [
                    'advisor' => $user?->name ?? 'our service advisor',
                    'lines' => $this->decisionLines($changes),
                ], $user);

                JobCardEstimateDecision::whereIn('id', collect($audit)->pluck('id'))
                    ->update(['customer_message_id' => $message->id]);
            }
        });

        if ($changes) {
            $this->resumeWorkIfAnswered($jobCard->fresh(), $estimate, $via);

            if ($via === Line::VIA_CUSTOMER) {
                $this->notifyAdvisor($jobCard, $estimate);
            }
        }

        return $changes;
    }

    /**
     * Mirror the sent estimate onto the job card: package price, discount, and one
     * billing item per approved additional line (removed again if declined).
     */
    public function syncJobCard(JobCard $jobCard, JobCardEstimate $estimate): void
    {
        $estimate->loadMissing('lines');
        $approvedKeys = [];

        foreach ($estimate->lines as $line) {
            if (!$line->isAdditional() || $line->approval_status !== Line::APPROVED) {
                continue;
            }

            $approvedKeys[] = $line->line_key;

            // GST-inclusive prices: tax is split out at invoicing (Phase 4), not added here
            $item = JobCardItem::updateOrCreate(
                ['job_card_id' => $jobCard->id, 'estimate_line_key' => $line->line_key],
                [
                    'type' => $line->kind,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => 'nos',
                    'unit_price' => $line->rate,
                    'discount' => 0,
                    'tax_rate' => 0,
                ]
            );

            if ($line->job_card_additional_job_id) {
                JobCardAdditionalJob::whereKey($line->job_card_additional_job_id)->update(['job_card_item_id' => $item->id]);
            }
        }

        JobCardItem::where('job_card_id', $jobCard->id)
            ->whereNotNull('estimate_line_key')
            ->whereNotIn('estimate_line_key', $approvedKeys)
            ->get()
            ->each->delete();

        $discount = $this->calculator->discountAmount($estimate);
        $pending = $this->hasPending($estimate);
        $hasAdditional = $estimate->lines->contains(fn (Line $l) => $l->isAdditional());

        $jobCard->forceFill([
            'package_price' => $estimate->package_price,
            'discount' => $discount,
            'discount_reason' => $discount > 0 ? 'Estimate discount' : null,
            'estimated_amount' => $estimate->grand_total,
            'customer_approved' => $hasAdditional && !$pending,
            'approved_at' => $hasAdditional && !$pending ? ($jobCard->approved_at ?? now()) : null,
        ])->save();

        $jobCard->calculateTotals();
    }

    public function hasPending(JobCardEstimate $estimate): bool
    {
        return $estimate->lines->contains(fn (Line $l) => $l->approval_status === Line::PENDING);
    }

    /**
     * Once nothing is pending, a job waiting for approval goes back to work by
     * itself, if the workflow allows it (e.g. a technician is assigned).
     */
    public function resumeWorkIfAnswered(JobCard $jobCard, JobCardEstimate $estimate, string $via): bool
    {
        // The job stages decide where it goes (back to work, or on to Awaiting Parts)
        return in_array(JobCardStatus::WorkInProgress->value, app(JobLifecycleService::class)->sync($jobCard, $via), true);
    }

    public function currentEstimate(JobCard $jobCard): JobCardEstimate
    {
        $estimate = JobCardEstimate::where('job_card_id', $jobCard->id)
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->with('lines')
            ->latest('version')
            ->first();

        if (!$estimate) {
            throw new EstimateException('No estimate has been sent to the customer yet.', [], 404);
        }

        return $estimate;
    }

    public function decisionsOpen(JobCard $jobCard): bool
    {
        return in_array($jobCard->status, config('workshop.customer_channel.decision_statuses', []), true);
    }

    protected function assertOpen(JobCard $jobCard): void
    {
        if (!$this->decisionsOpen($jobCard)) {
            throw new EstimateException('Approvals are closed for this job: work on the car has moved past this stage.');
        }
    }

    /**
     * Tell the job's advisor the customer has answered (approval loop in the life-cycle doc).
     */
    protected function notifyAdvisor(JobCard $jobCard, JobCardEstimate $estimate): void
    {
        $advisor = $jobCard->service_advisor_id ? User::find($jobCard->service_advisor_id) : null;
        if (!$advisor) {
            return;
        }

        $additional = $estimate->lines()->where('section', Line::SECTION_ADDITIONAL)->pluck('approval_status');

        $advisor->notify(StaffAlert::estimateAnswered(
            $jobCard,
            $additional->filter(fn ($s) => $s === Line::APPROVED)->count(),
            $additional->filter(fn ($s) => $s === Line::DECLINED)->count(),
            $additional->filter(fn ($s) => $s === Line::PENDING)->count(),
        ));
    }

    private function decisionLines(array $changes): string
    {
        return collect($changes)->map(fn (array $c) => __('customer_messages.decision_line', [
            'description' => $c['description'],
            'amount' => number_format($c['amount'], 0),
            'decision' => __('customer_messages.decision_' . $c['to']),
        ]))->implode("\n");
    }
}
