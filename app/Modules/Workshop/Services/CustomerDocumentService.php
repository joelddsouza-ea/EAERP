<?php

namespace App\Modules\Workshop\Services;

use App\Core\Services\ExportService;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;

/**
 * Printable estimate and service report (PDF), laid out like the owner's sheet.
 */
class CustomerDocumentService
{
    // DejaVu Sans (for ₹ and ✓) is large; embed only the glyphs used so WhatsApp-sized PDFs stay small
    private const PDF_OPTIONS = ['enable_font_subsetting' => true];

    public function __construct(
        protected ExportService $export,
        protected EstimatePresenter $presenter,
        protected ServiceReportService $reports,
    ) {}

    public function estimatePdf(JobCard $jobCard, JobCardEstimate $estimate, bool $forCustomer = false)
    {
        $jobCard->loadMissing(['customer', 'vehicle.make', 'vehicle.model']);

        return $this->export->toPdf('exports.estimate', [
            'company' => $this->company($jobCard),
            'jobCard' => $jobCard,
            'estimate' => $this->presenter->present($estimate, $forCustomer),
        ], 'estimate-' . $jobCard->job_number . '-v' . $estimate->version, options: self::PDF_OPTIONS);
    }

    public function reportPdf(JobCard $jobCard, bool $forCustomer = false)
    {
        return $this->export->toPdf('exports.service-report', [
            'company' => $this->company($jobCard),
            'report' => $this->reports->build($jobCard, $forCustomer),
        ], 'service-report-' . $jobCard->job_number, options: self::PDF_OPTIONS);
    }

    /**
     * Letterhead: organisation name with the branch's address and phone when set.
     */
    public function company(JobCard $jobCard): array
    {
        $jobCard->loadMissing('branch.organization');
        $branch = $jobCard->branch;
        $org = $branch?->organization;

        $address = collect([$branch?->address ?: $org?->address, $branch?->city ?: $org?->city])->filter()->implode(', ');

        return [
            'name' => $org?->name ?? config('app.name'),
            'tagline' => 'Multi-Brand Car Service Center',
            'address' => $address,
            'phone' => $branch?->phone ?: $org?->phone,
            'gstin' => $branch?->gstin ?: $org?->gstin,
        ];
    }
}
