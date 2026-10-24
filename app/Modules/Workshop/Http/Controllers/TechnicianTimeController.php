<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Hr\Services\WorkingHours;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\TechnicianTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workshop › Technician Time: working time per technician and per job (owner, managers).
 */
class TechnicianTimeController extends ApiController
{
    public function __construct(protected TechnicianTimeService $times) {}

    public function index(Request $request): JsonResponse
    {
        [$from, $to, $technicianId] = $this->range($request);

        return $this->success($this->times->report($from, $to, $technicianId) + [
            'technician_list' => User::active()->whereHas('roles', fn ($q) => $q->whereIn('slug', ['technician', 'body-shop-technician']))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$from, $to, $technicianId] = $this->range($request);
        $report = $this->times->report($from, $to, $technicianId);
        $h = fn (int $minutes) => round($minutes / 60, 2);

        return response()->streamDownload(function () use ($report, $h) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Job', 'Car', 'Plan / repair', 'Technicians', 'Work started', 'Submitted', 'In work (h)', 'Waiting approval (h)', 'Waiting parts (h)', 'At outside partner (h)', 'Working time (h)']);
            foreach ($report['jobs'] as $j) {
                fputcsv($out, [
                    $j['job_number'], $j['registration_no'], $j['service'], implode(', ', $j['technicians']),
                    $j['work_started_at'], $j['work_completed_at'],
                    $h($j['gross_minutes']), $h($j['approval_minutes']), $h($j['parts_minutes']), $h($j['outside_minutes']), $h($j['working_minutes']),
                ]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Technician', 'Jobs', 'Working time (h)', 'Average per job (h)']);
            foreach ($report['technicians'] as $t) {
                fputcsv($out, [$t['name'], $t['jobs'], $h($t['working_minutes']), $h($t['average_minutes'])]);
            }
            fclose($out);
        }, "technician-time-{$report['from']}-to-{$report['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The job card page: this job's working time over its whole life.
     */
    public function job(JobCard $jobCard): JsonResponse
    {
        $jobCard->load(['vehicle:id,registration_no', 'package:id,name', 'technicians:id,name', 'technician:id,name']);
        if (!$jobCard->work_started_at) {
            return $this->success(null);
        }
        $from = CarbonImmutable::parse($jobCard->work_started_at)->startOfDay();
        $to = CarbonImmutable::now();
        $hours = app(WorkingHours::class);
        $cache = [];

        return $this->success($this->times->jobTime($jobCard, $from, $to, function (User $u) use (&$cache, $hours, $from, $to) {
            return $cache[$u->id] ??= $hours->windows($u, $from, $to);
        }));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'technician_id' => 'nullable|integer',
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now())->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose at most a year.');

        return [$from, $to, isset($data['technician_id']) ? (int) $data['technician_id'] : null];
    }
}
