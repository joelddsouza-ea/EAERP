<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklistItem as Item;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Models\JobCardReport;
use App\Modules\Workshop\Models\ObservationRemark;

/**
 * The customer service report: activities carried out, additional jobs done,
 * observations with severity, and work recommended but not approved. It goes to
 * the customer only for plans with send_customer_report (360 / ELITE); for other
 * plans it stays in the system. The advisor chooses which parts are included.
 */
class ServiceReportService
{
    public function __construct(protected CustomerMessageService $messages) {}

    public function settings(JobCard $jobCard): JobCardReport
    {
        return JobCardReport::firstOrNew(['job_card_id' => $jobCard->id], [
            'include_activities' => true,
            'include_additional' => true,
            'include_observations' => true,
        ]);
    }

    public function canRelease(JobCard $jobCard): bool
    {
        $jobCard->loadMissing('package');

        return (bool) $jobCard->package?->send_customer_report;
    }

    public function update(JobCard $jobCard, array $data): JobCardReport
    {
        $report = $this->settings($jobCard);
        $report->fill($data)->save();

        return $report;
    }

    public function release(JobCard $jobCard, User $user): array
    {
        if (!$this->canRelease($jobCard)) {
            throw new EstimateException('The service report goes to the customer only for plans that include it (EA 360 and EA Elite).');
        }

        $report = $this->settings($jobCard);
        $report->fill(['released_at' => now(), 'released_by' => $user->id])->save();

        return [
            'report' => $report,
            'message' => $this->messages->prepare($jobCard, 'report_ready', [], $user),
        ];
    }

    /**
     * Report content. $forCustomer applies the advisor's include choices.
     */
    public function build(JobCard $jobCard, bool $forCustomer = false): array
    {
        $jobCard->loadMissing([
            'customer', 'vehicle.make', 'vehicle.model', 'package', 'serviceAdvisor:id,name',
            'checklists.items', 'observations.remark',
        ]);
        $settings = $this->settings($jobCard);

        $estimate = JobCardEstimate::where('job_card_id', $jobCard->id)
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->with('lines')
            ->latest('version')
            ->first();

        $additional = $estimate?->lines->filter(fn (Line $l) => $l->isAdditional()) ?? collect();

        $sections = [];
        $carriedOut = 0;
        foreach ($jobCard->checklists->whereIn('sheet_kind', ChecklistTemplate::WORK_SHEET_KINDS) as $sheet) {
            foreach ($sheet->items->sortBy('sort_order') as $item) {
                $mark = match ($item->status) {
                    Item::STATUS_DONE => '✓',
                    Item::STATUS_NOT_APPLICABLE => 'N/A',
                    Item::STATUS_NOT_DONE => '✗',
                    default => '',
                };
                if ($item->status === Item::STATUS_DONE) {
                    $carriedOut++;
                }
                $sections[$item->section_name ?: $sheet->name][] = ['name' => $item->item_name, 'mark' => $mark];
            }
        }

        $include = fn (string $part) => !$forCustomer || $settings->{'include_' . $part};

        return [
            'title' => ($jobCard->package?->name ?? 'Service') . ' Service Report',
            'job_number' => $jobCard->job_number,
            'date' => now()->toDateString(),
            'customer' => $jobCard->customer?->name,
            'car' => trim(($jobCard->vehicle?->make?->name ?? '') . ' ' . ($jobCard->vehicle?->model?->name ?? '')),
            'registration_no' => $jobCard->vehicle?->registration_no,
            'km_reading' => $jobCard->km_reading,
            'advisor' => $jobCard->serviceAdvisor?->name,
            'activities_carried_out' => $carriedOut,
            'sections' => $include('activities')
                ? collect($sections)->map(fn ($items, $name) => ['name' => $name, 'items' => $items])->values()->all()
                : [],
            'additional_jobs' => $include('additional')
                ? $additional->where('approval_status', Line::APPROVED)->map(fn (Line $l) => $l->description)->values()->all()
                : [],
            // Declined estimate lines are listed under "not approved" below, not twice
            'observations' => $include('observations')
                ? $jobCard->observations
                    ->where('source', '!=', JobCardObservation::SOURCE_ESTIMATE_DECLINED)
                    ->when($forCustomer, fn ($c) => $c->where('customer_visible', true))
                    ->map(fn ($o) => [
                        'text' => $o->text,
                        'severity' => $o->severity,
                        'remark' => $o->remark?->text($o->remark_days),
                    ])->values()->all()
                : [],
            'not_approved' => $include('additional')
                ? $additional->where('approval_status', Line::DECLINED)->map(function (Line $l) use ($jobCard) {
                    // Advice chosen on the Observations tab (other than the default "Customer not approved")
                    $observation = $jobCard->observations->firstWhere('estimate_line_key', $l->line_key);
                    $remark = $observation?->remark;

                    return $remark && $remark->code !== ObservationRemark::CUSTOMER_NOT_APPROVED
                        ? "{$l->description} — {$remark->text($observation->remark_days)}"
                        : $l->description;
                })->values()->all()
                : [],
            'not_approved_label' => Line::DECLINED_LABEL,
            'next_service_km' => $estimate?->next_service_km,
            'next_service_date' => $estimate?->next_service_date?->toDateString(),
            'advisor_notes' => $settings->advisor_notes,
            'released_at' => $settings->released_at?->toIso8601String(),
            'sends_to_customer' => $this->canRelease($jobCard),
            'include' => [
                'activities' => $settings->include_activities,
                'additional' => $settings->include_additional,
                'observations' => $settings->include_observations,
            ],
        ];
    }
}
