<?php

namespace App\Modules\Fleet\Services;

use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\CustomerApp\Services\CustomerNotifier;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Modules\Workshop\Services\CustomerMessageService;
use App\Modules\Workshop\Services\EstimateDecisionService;
use App\Modules\Workshop\Services\EstimateException;
use Illuminate\Support\Facades\DB;

/**
 * A fleet driver's answers on an estimate. Drivers may decline any line, and approve extra
 * work while the total they approved on the job stays within the fleet's limit (its own, else
 * the Settings › Fleet default). Approvals above it stay pending: the fleet owner is asked
 * (app + WhatsApp) and the advisor told. Lines the owner (or the advisor for them) already
 * decided are the owner's: a driver can't change them.
 */
class FleetApprovalService
{
    public function __construct(
        protected EstimateDecisionService $decisions,
        protected CustomerMessageService $messages,
        protected CustomerNotifier $notifier,
    ) {}

    public function limitFor(Customer $fleet): float
    {
        return (float) ($fleet->fleet_driver_limit ?? WorkshopSettings::get('fleet.driver_limit') ?? 0);
    }

    /**
     * What drivers have approved on the job's current estimate (GST-inclusive).
     */
    public function used(JobCard $job): float
    {
        $estimate = $this->sentEstimate($job);

        return $estimate ? $this->usedOn($estimate->lines) : 0.0;
    }

    /**
     * What a driver may still approve on the job.
     */
    public function remaining(JobCard $job): float
    {
        $job->loadMissing('customer');

        return max(0, round($this->limitFor($job->customer) - $this->used($job), 2));
    }

    /**
     * @param array<string, string> $decisions line_key => approved|declined
     * @return array{changes: array, held: array<int, array{line_key: string, description: string, amount: float}>, owner_decided: array<int, string>, remaining: float}
     */
    public function decide(JobCard $job, FleetDriver $driver, array $decisions, ?string $ip = null): array
    {
        $job->loadMissing('customer');
        if ($driver->customer_id !== $job->customer_id) {
            throw new EstimateException('This job is not for your fleet.', [], 404);
        }

        return DB::transaction(function () use ($job, $driver, $decisions, $ip) {
            // One driver's answers at a time per estimate, so two phones can't both use the same limit
            $estimate = $this->decisions->currentEstimate($job);
            JobCardEstimate::whereKey($estimate->id)->lockForUpdate()->first();
            $lines = $estimate->lines()->get()->keyBy('line_key');
            $limit = $this->limitFor($job->customer);

            $accepted = [];
            $approvals = [];
            $held = [];
            $ownerDecided = [];

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
                if ($this->ownerLine($line)) {
                    if ($line->approval_status !== $decision) {
                        $ownerDecided[] = $line->description;
                    }
                } elseif ($decision === Line::DECLINED) {
                    $accepted[$key] = $decision;
                } elseif ($line->approval_status !== Line::APPROVED) {
                    $approvals[] = $line;
                }
            }

            // Lines declined now free up their share of the limit
            $used = $this->usedOn($lines->reject(fn (Line $l) => isset($accepted[$l->line_key])));

            foreach ($approvals as $line) {
                $key = $line->line_key;
                $amount = (float) $line->amount;
                if ($used + $amount <= $limit + 0.001) {
                    $accepted[$key] = Line::APPROVED;
                    $used += $amount;
                } else {
                    $held[] = ['line_key' => $key, 'description' => $line->description, 'amount' => $amount];
                }
            }

            $changes = $accepted
                ? $this->decisions->decide($job, $accepted, Line::VIA_DRIVER, null, null, $ip, $driver->id)
                : [];

            if ($held) {
                $this->askOwner($job, $driver, $held, $limit);
            }

            return [
                'changes' => $changes,
                'held' => $held,
                'owner_decided' => $ownerDecided,
                'remaining' => max(0, round($limit - $used, 2)),
            ];
        });
    }

    /**
     * Shown on the driver's job page: their limit and what is left.
     */
    public function pageInfo(JobCard $job, FleetDriver $driver): array
    {
        $job->loadMissing('customer');
        $estimate = $this->sentEstimate($job);

        return [
            'driver' => $driver->name,
            'limit' => $this->limitFor($job->customer),
            'remaining' => $this->remaining($job),
            // Lines the owner decided: the driver's buttons are off for these
            'owner_lines' => $estimate ? $estimate->lines->filter(fn (Line $l) => $this->ownerLine($l))->pluck('line_key')->values() : [],
        ];
    }

    /**
     * Decided by the owner (or the advisor for them): a driver can't change it.
     */
    public function ownerLine(Line $line): bool
    {
        return $line->approval_status !== Line::PENDING && !$line->decided_by_driver_id
            && in_array($line->decided_via, [Line::VIA_CUSTOMER, Line::VIA_ADVISOR], true);
    }

    protected function askOwner(JobCard $job, FleetDriver $driver, array $held, float $limit): void
    {
        $job->loadMissing(['vehicle:id,registration_no', 'customer']);
        $total = array_sum(array_column($held, 'amount'));
        $reg = $job->vehicle?->registration_no ?? $job->job_number;
        $lines = collect($held)->map(fn ($h) => __('customer_messages.decision_line', [
            'description' => $h['description'],
            'amount' => number_format($h['amount'], 0),
            'decision' => 'waiting for you',
        ]))->implode("\n");

        DB::afterCommit(function () use ($job, $driver, $held, $limit, $total, $reg, $lines) {
            $this->notifier->notify(
                $job->customer_id,
                'approval',
                "{$driver->firstName()} asks you to approve Rs " . number_format($total, 0) . " on {$reg}",
                'Above the driver\'s limit of Rs ' . number_format($limit, 0) . ': ' . collect($held)->pluck('description')->take(3)->implode(', '),
                "/app/jobs/{$job->id}",
                'fleet_ask:' . $job->id . ':' . md5(implode('|', array_column($held, 'line_key'))),
            );

            $this->messages->prepare($job, 'fleet_owner_approval', [
                'driver' => $driver->name,
                'limit' => number_format($limit, 0),
                'lines' => $lines,
            ]);

            $advisor = $job->service_advisor_id ? User::find($job->service_advisor_id) : null;
            $advisor?->notify(new StaffAlert(
                'fleet_owner_asked',
                "Fleet owner asked to approve — {$job->job_number}",
                "{$driver->name} approved up to the limit; Rs " . number_format($total, 0) . ' waits for the fleet owner. Send the WhatsApp message from the job card.',
                "/job-cards/{$job->id}/estimate",
                $job->id,
            ));
        });
    }

    protected function usedOn($lines): float
    {
        return round((float) collect($lines)
            ->filter(fn (Line $l) => $l->isAdditional() && $l->approval_status === Line::APPROVED && $l->decided_by_driver_id)
            ->sum(fn (Line $l) => (float) $l->amount), 2);
    }

    protected function sentEstimate(JobCard $job): ?JobCardEstimate
    {
        return JobCardEstimate::with('lines')->where('job_card_id', $job->id)
            ->where('status', JobCardEstimate::STATUS_SENT)->latest('version')->first();
    }
}
