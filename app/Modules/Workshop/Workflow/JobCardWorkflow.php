<?php

namespace App\Modules\Workshop\Workflow;

use App\Core\Models\User;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guards\EstimateResponded;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;
use App\Modules\Workshop\Workflow\Guards\JobSheetActivitiesResolved;
use App\Modules\Workshop\Workflow\Guards\NoOpenCriticalIssues;
use App\Modules\Workshop\Workflow\Guards\PreDeliveryChecklistComplete;
use App\Modules\Workshop\Workflow\Guards\TechnicianAssigned;

/**
 * Job card status machine.
 *
 * Each edge lists the permissions allowed to take it (any of), guard classes that
 * must pass, and whether notes are mandatory. Statuses disabled in
 * config('workshop.workflow.disabled_statuses') are routed around: their outgoing
 * edges are followed, combining guards, so enabling a status later is config-only.
 */
class JobCardWorkflow
{
    private const CHANGE = 'workshop.job-cards.change-status';
    private const SHEET = 'workshop.job-sheets.complete';
    private const QC = 'workshop.qc.update';
    private const PRE_DELIVERY = 'workshop.pre-delivery.update';
    private const PARTS = 'workshop.spare-parts.update';

    /**
     * @var array<string, array<string, array{perms: string[], guards?: class-string<Guard>[], requires_notes?: bool}>>
     */
    public const EDGES = [
        'pickup_scheduled' => [
            'pickup_en_route' => ['perms' => [self::CHANGE]],
        ],
        'pickup_en_route' => [
            'vehicle_collected' => ['perms' => [self::CHANGE]],
        ],
        'vehicle_collected' => [
            'intake_pending' => ['perms' => [self::CHANGE]],
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
            'post_work_trial' => [
                'perms' => [self::CHANGE, self::SHEET],
                'guards' => [JobSheetActivitiesResolved::class, NoOpenCriticalIssues::class],
            ],
        ],
        'awaiting_parts' => [
            'work_in_progress' => ['perms' => [self::CHANGE, self::SHEET, self::PARTS]],
        ],
        'post_work_trial' => [
            'wash_pending' => ['perms' => [self::QC]],
            // QA failed: back to the technician
            'work_in_progress' => ['perms' => [self::QC], 'requires_notes' => true],
        ],
        'wash_pending' => [
            'ready_for_delivery' => ['perms' => [self::PRE_DELIVERY, self::CHANGE], 'guards' => [PreDeliveryChecklistComplete::class]],
            'work_in_progress' => ['perms' => [self::QC], 'requires_notes' => true],
        ],
        'ready_for_delivery' => [
            'invoiced' => ['perms' => ['billing.invoices.create']],
            'wash_pending' => ['perms' => [self::CHANGE], 'requires_notes' => true],
        ],
        'invoiced' => [
            'gate_pass_generated' => ['perms' => ['billing.payments.create']],
        ],
        'gate_pass_generated' => [
            'delivered' => ['perms' => [self::CHANGE, 'billing.payments.create']],
        ],
    ];

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
            $permitted = $user->hasAnyPermission($edge['perms']);
            $failures = $this->failedGuards($jobCard, $edge);

            $options[] = [
                'status' => $to,
                'label' => JobCardStatus::from($to)->label(),
                'allowed' => $permitted && empty($failures),
                'permitted' => $permitted,
                'requires_notes' => $edge['requires_notes'],
                'can_override' => $permitted && !empty($failures)
                    && $user->hasPermission('workshop.job-cards.override-guards'),
                'blocked_by' => array_map(fn (GuardFailure $f) => $f->toArray(), $failures),
            ];
        }

        return $options;
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
        ];
    }
}
