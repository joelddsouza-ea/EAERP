<?php

namespace App\Modules\Workshop\Workflow;

use App\Core\Models\User;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Workflow\Guards\BillApproved;
use App\Modules\Workshop\Workflow\Guards\EstimateResponded;
use App\Modules\Workshop\Workflow\Guards\GatePassIssued;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;
use App\Modules\Workshop\Workflow\Guards\JobSheetActivitiesResolved;
use App\Modules\Workshop\Workflow\Guards\NoOpenCriticalIssues;
use App\Modules\Workshop\Workflow\Guards\PickupHandoverCaptured;
use App\Modules\Workshop\Workflow\Guards\OutsideWorkReturned;
use App\Modules\Workshop\Workflow\Guards\PreDeliveryChecklistComplete;
use App\Modules\Workshop\Workflow\Guards\TechnicianAssigned;

/**
 * Job card status machine.
 *
 * Each edge lists the permissions allowed to take it (any of), guard classes that
 * must pass, and whether notes are mandatory. Statuses disabled in
 * config('workshop.workflow.disabled_statuses') are routed around: their outgoing
 * edges are followed, combining guards, so enabling a status later is config-only.
 *
 * Stages marked automatic in the Job stages master are entered by the system
 * (JobLifecycleService). People take only the "event" edges (QC result, send back,
 * cancel); any other move into an automatic stage is a manual correction that only
 * an owner/manager may make, with a reason.
 */
class JobCardWorkflow
{
    private const CHANGE = 'workshop.job-cards.change-status';
    private const SHEET = 'workshop.job-sheets.complete';
    private const QC = 'workshop.qc.update';
    private const PRE_DELIVERY = 'workshop.pre-delivery.update';
    private const PARTS = 'workshop.spare-parts.update';
    private const DRIVE = 'workshop.trips.drive';
    private const TRIPS = 'workshop.trips.manage';

    /**
     * @var array<string, array<string, array{perms: string[], guards?: class-string<Guard>[], requires_notes?: bool}>>
     */
    public const EDGES = [
        // Pickup by a driver (the trip screen takes these edges); the driver's photos are the intake
        'pickup_scheduled' => [
            'pickup_en_route' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS]],
            // The customer brought the car in after all (the pickup is cancelled)
            'intake_pending' => ['perms' => [self::CHANGE], 'requires_notes' => true],
        ],
        'pickup_en_route' => [
            'vehicle_collected' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS], 'guards' => [PickupHandoverCaptured::class]],
            'pickup_scheduled' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS, self::CHANGE], 'requires_notes' => true],
        ],
        'vehicle_collected' => [
            'intake_pending' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS, self::CHANGE]],
        ],
        // Home service: the technician travels to the customer, then the job runs as usual
        'home_visit_scheduled' => [
            'home_visit_en_route' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS]],
            'intake_pending' => ['perms' => [self::CHANGE], 'requires_notes' => true],
        ],
        'home_visit_en_route' => [
            'intake_pending' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS, self::CHANGE]],
            'home_visit_scheduled' => ['trip' => true, 'perms' => [self::DRIVE, self::TRIPS, self::CHANGE], 'requires_notes' => true],
        ],
        'intake_pending' => [
            'intake_in_progress' => ['perms' => [self::CHANGE]],
        ],
        'intake_in_progress' => [
            'awaiting_approval' => ['perms' => [self::CHANGE]],
            'work_in_progress' => ['perms' => [self::CHANGE], 'guards' => [IntakeCaptured::class, TechnicianAssigned::class]],
        ],
        'awaiting_approval' => [
            'work_in_progress' => ['perms' => [self::CHANGE], 'guards' => [IntakeCaptured::class, TechnicianAssigned::class, EstimateResponded::class]],
        ],
        'work_in_progress' => [
            'awaiting_parts' => ['perms' => [self::CHANGE, self::SHEET]],
            'awaiting_approval' => ['perms' => [self::CHANGE]],
            // The trial drive and the wash are sections of the technician's job sheet (owner, 2026-10-24):
            // the submitted job goes straight to the advisor's final QC
            'qc_pending' => [
                'perms' => [self::CHANGE, self::SHEET],
                'guards' => [JobSheetActivitiesResolved::class, NoOpenCriticalIssues::class, OutsideWorkReturned::class],
            ],
        ],
        'awaiting_parts' => [
            'work_in_progress' => ['perms' => [self::CHANGE, self::SHEET, self::PARTS]],
        ],
        // No longer entered (trial and wash are on the job sheet): jobs left in them move on to final QC
        'post_work_trial' => [
            'qc_pending' => ['perms' => [self::QC, self::CHANGE]],
            'work_in_progress' => ['perms' => [self::QC], 'requires_notes' => true],
        ],
        'wash_pending' => [
            'qc_pending' => ['perms' => [self::QC, self::CHANGE]],
            'work_in_progress' => ['perms' => [self::QC], 'requires_notes' => true],
        ],
        // Final QC by the advisor: the pre-delivery checklist; a failed check goes back to the technician
        'qc_pending' => [
            'ready_for_delivery' => ['perms' => [self::PRE_DELIVERY, self::CHANGE], 'guards' => [PreDeliveryChecklistComplete::class]],
            'work_in_progress' => ['perms' => [self::QC], 'requires_notes' => true],
        ],
        'ready_for_delivery' => [
            'invoiced' => ['perms' => ['billing.invoices.create'], 'guards' => [BillApproved::class]],
            'work_in_progress' => ['perms' => [self::CHANGE], 'requires_notes' => true],
        ],
        'invoiced' => [
            'gate_pass_generated' => ['perms' => ['billing.payments.create'], 'guards' => [GatePassIssued::class]],
        ],
        'gate_pass_generated' => [
            'delivered' => ['perms' => [self::CHANGE, 'billing.payments.create'], 'guards' => [GatePassIssued::class]],
        ],
    ];

    /**
     * Moves people make as events whatever the stage settings: the QC result and
     * sending a car back.
     */
    private const EVENT_EDGES = [
        'post_work_trial' => ['work_in_progress'],
        'wash_pending' => ['work_in_progress'],
        'qc_pending' => ['work_in_progress'],
        'ready_for_delivery' => ['work_in_progress'],
    ];

    /**
     * Stage order, to tell a car sent back (e.g. QC failed) from normal progress.
     */
    private const ORDER = [
        'intake_pending' => 1, 'intake_in_progress' => 2,
        'awaiting_approval' => 3, 'work_in_progress' => 3, 'awaiting_parts' => 3,
        'post_work_trial' => 4, 'wash_pending' => 5, 'qc_pending' => 6, 'ready_for_delivery' => 7,
        'invoiced' => 8, 'gate_pass_generated' => 9, 'delivered' => 10,
    ];

    public const OVERRIDE_PERMISSION = 'workshop.job-cards.override-guards';

    /**
     * Guards no override can skip.
     */
    public const HARD_GUARDS = [GatePassIssued::CODE];

    /**
     * Any non-terminal status may be cancelled, with a reason.
     */
    private const CANCEL_EDGE = ['perms' => [self::CHANGE], 'requires_notes' => true];

    public function isDisabled(string $status): bool
    {
        return in_array($status, config('workshop.workflow.disabled_statuses', []), true);
    }

    /**
     * Effective edges out of a status, after routing around disabled statuses.
     *
     * @return array<string, array{perms: string[], guards: class-string<Guard>[], requires_notes: bool}>
     */
    public function edgesFrom(string $status): array
    {
        $edges = $this->resolve($status, [], [$status]);

        if (!in_array($status, JobCardStatus::terminal(), true)) {
            $edges[JobCardStatus::Cancelled->value] = self::normalize(self::CANCEL_EDGE);
        }

        return $edges;
    }

    public function edge(string $from, string $to): ?array
    {
        return $this->edgesFrom($from)[$to] ?? null;
    }

    /**
     * Next statuses for a job card with permission + guard evaluation, for the UI.
     *
     * @return array<int, array{status: string, label: string, allowed: bool, permitted: bool, requires_notes: bool, blocked_by: array}>
     */
    public function transitionsFor(JobCard $jobCard, User $user): array
    {
        $options = [];

        foreach ($this->edgesFrom($jobCard->status) as $to => $edge) {
            if ($edge['trip']) {
                continue;
            }
            $manual = $this->isManualCorrection($jobCard, $to);
            $permitted = $this->mayTake($jobCard, $user, $to, $edge);
            $failures = $this->failedGuards($jobCard, $edge);
            $hard = array_filter($failures, fn (GuardFailure $f) => in_array($f->code, self::HARD_GUARDS, true));

            $options[] = [
                'status' => $to,
                'label' => JobCardStatus::from($to)->label(),
                'allowed' => $permitted && empty($failures),
                'permitted' => $permitted,
                'manual' => $manual,
                'requires_notes' => $edge['requires_notes'] || $manual,
                'can_override' => $permitted && !empty($failures) && !$hard
                    && $user->hasPermission(self::OVERRIDE_PERMISSION),
                'blocked_by' => array_map(fn (GuardFailure $f) => $f->toArray(), $failures),
            ];
        }

        return $options;
    }

    /**
     * A move into a stage the system enters by itself, other than an event
     * (QC result, send back, cancel) or moving on after the car was sent back.
     */
    public function isManualCorrection(JobCard $jobCard, string $to): bool
    {
        $from = $jobCard->status;

        if ($to === JobCardStatus::Cancelled->value || in_array($to, self::EVENT_EDGES[$from] ?? [], true)) {
            return false;
        }

        if (!WorkflowStage::isAuto($to)) {
            return false;
        }

        // Sent back to work (trial drive / QC failed): the system waits for people to move it on again
        return !($from === JobCardStatus::WorkInProgress->value && $this->enteredBackwards($jobCard) && $this->order($to) > $this->order($from));
    }

    public function mayTake(JobCard $jobCard, User $user, string $to, array $edge): bool
    {
        return $this->isManualCorrection($jobCard, $to)
            ? $user->hasPermission(self::OVERRIDE_PERMISSION)
            : $user->hasAnyPermission($edge['perms']);
    }

    /**
     * Whether the job came into its current status from a later stage.
     */
    public function enteredBackwards(JobCard $jobCard): bool
    {
        $from = JobCardStatusHistory::where('job_card_id', $jobCard->id)
            ->where('to_status', $jobCard->status)
            ->latest('id')
            ->value('from_status');

        return $from !== null && $this->order($from) > $this->order($jobCard->status);
    }

    public function isForward(string $from, string $to): bool
    {
        return $this->order($to) > $this->order($from);
    }

    private function order(string $status): int
    {
        return self::ORDER[$status] ?? 0;
    }

    /**
     * @return GuardFailure[]
     */
    public function failedGuards(JobCard $jobCard, array $edge): array
    {
        $failures = [];

        foreach ($edge['guards'] as $guardClass) {
            $failure = app($guardClass)->check($jobCard);
            if ($failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param string[] $inheritedGuards guards collected while routing through disabled statuses
     * @param string[] $visited
     */
    private function resolve(string $status, array $inheritedGuards, array $visited): array
    {
        $result = [];

        foreach (self::EDGES[$status] ?? [] as $to => $edge) {
            $edge = self::normalize($edge);
            $guards = array_values(array_unique([...$inheritedGuards, ...$edge['guards']]));

            if ($this->isDisabled($to)) {
                if (in_array($to, $visited, true)) {
                    continue;
                }
                foreach ($this->resolve($to, $guards, [...$visited, $to]) as $nextTo => $nextEdge) {
                    // Skip edges that loop back to where we started (e.g. work -> awaiting_approval -> work)
                    if ($nextTo !== $visited[0] && !isset($result[$nextTo])) {
                        $nextEdge['requires_notes'] = $nextEdge['requires_notes'] || $edge['requires_notes'];
                        $result[$nextTo] = $nextEdge;
                    }
                }
                continue;
            }

            $result[$to] = [...$edge, 'guards' => $guards];
        }

        return $result;
    }

    private static function normalize(array $edge): array
    {
        return [
            'perms' => $edge['perms'],
            'guards' => $edge['guards'] ?? [],
            'requires_notes' => $edge['requires_notes'] ?? false,
            // Taken only from the trip screen (TripService), so the trip and the job move together
            'trip' => $edge['trip'] ?? false,
        ];
    }
}
