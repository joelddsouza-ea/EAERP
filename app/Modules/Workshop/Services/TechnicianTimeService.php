<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Hr\Services\WorkingHours;
use App\Modules\Workshop\Enums\JobCardStatus as S;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\JobOutsideWork;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How long technicians actually worked on jobs: the time the job was in Work In Progress
 * (waiting for the customer's approval or for parts are separate stages, so they fall out),
 * less the time the car was at an outside partner (washing, wheel alignment), counted only
 * within each technician's shift hours (no weekly off, holidays, leave; break deducted).
 * Every technician on a job is credited with its full working time.
 */
class TechnicianTimeService
{
    public function __construct(protected WorkingHours $hours) {}

    /**
     * @return array{from: string, to: string, technicians: array, jobs: array}
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to, ?int $technicianId = null): array
    {
        $jobs = JobCard::with(['vehicle:id,registration_no', 'package:id,name', 'technicians:id,name', 'technician:id,name'])
            ->whereNotNull('work_started_at')
            ->where('work_started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('work_completed_at')->orWhere('work_completed_at', '>=', $from))
            ->when($technicianId, fn ($q) => $q->where(fn ($t) => $t->where('technician_id', $technicianId)
                ->orWhereHas('technicians', fn ($p) => $p->where('users.id', $technicianId))))
            ->orderBy('work_started_at')
            ->get();

        $windowCache = [];
        $windowsFor = function (User $u) use (&$windowCache, $from, $to) {
            return $windowCache[$u->id] ??= $this->hours->windows($u, $from, $to);
        };

        $rows = [];
        $perTech = [];
        foreach ($jobs as $job) {
            $row = $this->jobTime($job, $from, $to, $windowsFor);
            if ($row['gross_minutes'] <= 0 && $row['working_minutes'] <= 0) {
                continue;
            }
            $rows[] = $row;
            foreach ($row['per_technician'] as $t) {
                if ($technicianId && $t['id'] !== $technicianId) {
                    continue;
                }
                $perTech[$t['id']] ??= ['id' => $t['id'], 'name' => $t['name'], 'jobs' => 0, 'working_minutes' => 0];
                $perTech[$t['id']]['jobs']++;
                $perTech[$t['id']]['working_minutes'] += $t['minutes'];
            }
        }

        $technicians = collect($perTech)
            ->map(fn ($t) => $t + ['average_minutes' => $t['jobs'] ? (int) round($t['working_minutes'] / $t['jobs']) : 0])
            ->sortByDesc('working_minutes')->values()->all();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'technicians' => $technicians, 'jobs' => $rows];
    }

    /**
     * One job's times (the job card page shows the same, over the whole job).
     *
     * @param callable(User): array $windowsFor
     */
    public function jobTime(JobCard $job, CarbonImmutable $from, CarbonImmutable $to, callable $windowsFor): array
    {
        $spans = $this->statusSpans($job, $from, $to);
        $outside = $this->outsideIntervals($job, $from, $to);
        $wip = $spans[S::WorkInProgress->value] ?? [];
        $net = self::subtract($wip, $outside);

        $techs = $this->technicians($job);
        $perTech = $techs->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'minutes' => $this->workingMinutes($net, $windowsFor($u)),
        ])->values()->all();

        // The job's own working time: its lead technician's hours (the first one when there is no lead)
        $lead = $techs->firstWhere('id', $job->technician_id) ?? $techs->first();
        $leadWindows = $lead ? $windowsFor($lead) : null;
        $measure = fn (array $intervals) => $leadWindows !== null ? $this->workingMinutes($intervals, $leadWindows) : self::minutes($intervals);

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'registration_no' => $job->vehicle?->registration_no,
            'service' => $job->package?->name ?? ($job->service_type ? ucwords(str_replace('_', ' ', $job->service_type)) : null),
            'status' => $job->status,
            'technicians' => $techs->pluck('name')->values()->all(),
            'work_started_at' => $job->work_started_at?->toIso8601String(),
            'work_completed_at' => $job->work_completed_at?->toIso8601String(),
            // All in working hours (the lead technician's shift)
            'gross_minutes' => $measure($wip),
            'approval_minutes' => $measure($spans[S::AwaitingApproval->value] ?? []),
            'parts_minutes' => $measure($spans[S::AwaitingParts->value] ?? []),
            'outside_minutes' => $measure(self::intersect($wip, $outside)),
            'working_minutes' => $measure($net),
            'per_technician' => $perTech,
        ];
    }

    /**
     * Time in each status from the history, clipped to [from, to].
     *
     * @return array<string, array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>>
     */
    protected function statusSpans(JobCard $job, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $history = JobCardStatusHistory::where('job_card_id', $job->id)->orderBy('created_at')->orderBy('id')->get(['to_status', 'created_at']);
        $spans = [];
        $now = CarbonImmutable::now();

        foreach ($history as $i => $h) {
            $start = CarbonImmutable::parse($h->created_at);
            $end = isset($history[$i + 1]) ? CarbonImmutable::parse($history[$i + 1]->created_at) : $now;
            $s = $start->max($from);
            $e = $end->min($to);
            if ($e->gt($s)) {
                $spans[$h->to_status][] = [$s, $e];
            }
        }

        return $spans;
    }

    /**
     * When the car was away at an outside partner.
     */
    protected function outsideIntervals(JobCard $job, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return JobOutsideWork::where('job_card_id', $job->id)->whereNotNull('sent_at')->get()
            ->map(function (JobOutsideWork $w) use ($from, $to) {
                $s = CarbonImmutable::parse($w->sent_at)->max($from);
                $e = CarbonImmutable::parse($w->returned_at ?? $w->cancelled_at ?? now())->min($to);

                return $e->gt($s) ? [$s, $e] : null;
            })->filter()->values()->all();
    }

    /**
     * The lead technician and co-technicians on the job now.
     *
     * @return Collection<int, User>
     */
    protected function technicians(JobCard $job): Collection
    {
        return $job->technicians->concat($job->technician ? [$job->technician] : [])->unique('id')->values();
    }

    /**
     * Minutes of the intervals that fall inside the working windows, less the break share.
     */
    protected function workingMinutes(array $intervals, array $windows): int
    {
        $total = 0.0;
        foreach ($windows as [$ws, $we, $factor]) {
            foreach ($intervals as [$s, $e]) {
                $a = $s->max($ws);
                $b = $e->min($we);
                if ($b->gt($a)) {
                    $total += $a->diffInSeconds($b) / 60 * $factor;
                }
            }
        }

        return (int) round($total);
    }

    private static function minutes(array $intervals): int
    {
        return (int) round(array_sum(array_map(fn ($i) => $i[0]->diffInSeconds($i[1]) / 60, $intervals)));
    }

    /**
     * Parts of $intervals not covered by $remove.
     */
    private static function subtract(array $intervals, array $remove): array
    {
        foreach ($remove as [$rs, $re]) {
            $next = [];
            foreach ($intervals as [$s, $e]) {
                if ($re->lte($s) || $rs->gte($e)) {
                    $next[] = [$s, $e];
                    continue;
                }
                if ($rs->gt($s)) {
                    $next[] = [$s, $rs];
                }
                if ($re->lt($e)) {
                    $next[] = [$re, $e];
                }
            }
            $intervals = $next;
        }

        return $intervals;
    }

    private static function intersect(array $a, array $b): array
    {
        $out = [];
        foreach ($a as [$s, $e]) {
            foreach ($b as [$bs, $be]) {
                $x = $s->max($bs);
                $y = $e->min($be);
                if ($y->gt($x)) {
                    $out[] = [$x, $y];
                }
            }
        }

        return $out;
    }
}
