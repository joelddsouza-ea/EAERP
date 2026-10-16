<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\CustomerLink;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateComment;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Services\CustomerDocumentService;
use App\Modules\Workshop\Services\CustomerGalleryService;
use App\Modules\Workshop\Services\EstimateDecisionService;
use App\Modules\Workshop\Services\EstimateException;
use App\Modules\Workshop\Services\EstimatePresenter;
use App\Modules\Workshop\Services\ServiceReportService;
use App\Modules\Workshop\Services\CustomerLinkService;
use App\Modules\Billing\Services\BillingDocuments;
use App\Modules\Billing\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer's login-free job page. The token in the URL is the only credential:
 * it is looked up by hash, can be revoked, and expires. Only customer-visible
 * information is returned.
 */
class PublicJobController extends ApiController
{
    public function __construct(
        protected CustomerLinkService $links,
        protected EstimatePresenter $presenter,
        protected EstimateDecisionService $decisions,
        protected ServiceReportService $reports,
    ) {}

    public function show(string $token): JsonResponse
    {
        [$link, $jobCard] = $this->resolve($token);
        $link->update(['last_viewed_at' => now()]);

        $jobCard->load([
            'customer:id,name',
            'vehicle:id,registration_no,make_id,model_id',
            'vehicle.make:id,name',
            'vehicle.model:id,name',
            'package:id,name,send_customer_report',
            'statusHistory',
            'serviceAdvisor:id,name,phone',
            'branch.organization',
        ]);

        $estimate = $this->sentEstimate($jobCard);
        if ($estimate && !$estimate->customer_viewed_at) {
            $estimate->update(['customer_viewed_at' => now()]);
        }

        $report = $this->reports->settings($jobCard);
        $org = $jobCard->branch?->organization;

        return $this->success([
            'workshop' => [
                'name' => $org?->name ?? config('app.name'),
                'phone' => $jobCard->branch?->phone ?: $org?->phone,
            ],
            'job' => [
                'job_number' => $jobCard->job_number,
                'status' => $jobCard->status,
                'status_label' => JobCardStatus::tryFrom($jobCard->status)?->label() ?? $jobCard->status,
                'customer_name' => $jobCard->customer?->name,
                'car' => trim(($jobCard->vehicle?->make?->name ?? '') . ' ' . ($jobCard->vehicle?->model?->name ?? '')),
                'registration_no' => $jobCard->vehicle?->registration_no,
                'plan' => $jobCard->package?->name,
                'advisor' => $jobCard->serviceAdvisor?->name,
                'advisor_phone' => $jobCard->serviceAdvisor?->phone,
                'timeline' => $jobCard->statusHistory
                    ->sortBy('id')
                    ->map(fn ($h) => [
                        'status' => $h->to_status,
                        'label' => JobCardStatus::tryFrom($h->to_status)?->label() ?? $h->to_status,
                        'at' => $h->created_at?->toIso8601String(),
                    ])->values(),
            ],
            'estimate' => $estimate ? $this->presenter->present($estimate, forCustomer: true) : null,
            'decisions_open' => $this->decisions->decisionsOpen($jobCard),
            // Section-wise: arrival, job sheets by section, additional work before/after
            'gallery' => app(CustomerGalleryService::class)->build($jobCard),
            'version' => $this->pageVersion($jobCard),
            'report' => $report->released_at ? $this->reports->build($jobCard, forCustomer: true) : null,
            'bill' => $this->customerBill($jobCard),
        ]);
    }

    /**
     * The customer's page asks this every few seconds and reloads when it changes.
     */
    public function version(string $token): JsonResponse
    {
        [, $jobCard] = $this->resolve($token);

        return $this->success(['version' => $this->pageVersion($jobCard)]);
    }

    /**
     * Changes whenever something the customer sees changes: status, estimate, photos/videos,
     * report, bill and payments.
     */
    private function pageVersion(JobCard $jobCard): string
    {
        $media = JobCardMedia::where('job_card_id', $jobCard->id)->where('customer_visible', true)
            ->selectRaw('COUNT(*) c, MAX(id) m')->first();
        $estimate = JobCardEstimate::where('job_card_id', $jobCard->id)->max('updated_at');
        $bill = \App\Modules\Billing\Models\Invoice::withoutGlobalScopes()->where('job_card_id', $jobCard->id)->max('updated_at');
        $paid = \App\Modules\Billing\Models\Payment::withoutGlobalScopes()->where('job_card_id', $jobCard->id)->max('id');
        $report = \App\Modules\Workshop\Models\JobCardReport::where('job_card_id', $jobCard->id)->value('released_at');

        return md5(implode('|', [$jobCard->status, $jobCard->status_changed_at, $media?->c, $media?->m, $estimate, $bill, $paid, $report]));
    }

    public function billPdf(string $token, BillingDocuments $documents)
    {
        [, $jobCard] = $this->resolve($token);
        $bill = app(BillingService::class)->payableBill($jobCard) ?? throw new EstimateException('The bill is not ready yet.', [], 404);

        return $documents->billPdf($bill);
    }

    /**
     * The approved bill with what has been paid (nothing before back office approves it).
     */
    private function customerBill(JobCard $jobCard): ?array
    {
        $billing = app(BillingService::class);
        $bill = $billing->payableBill($jobCard);

        if (!$bill) {
            return null;
        }

        $summary = $billing->summary($jobCard);

        return [
            'number' => $bill->invoice_number,
            'kind' => $bill->kind,
            'total' => (float) $bill->total_amount,
            'paid' => $summary['paid'],
            'balance' => max(0, $summary['balance']),
            'refund_due' => $summary['refund_due'],
            'gate_pass' => $summary['gate_pass']['number'] ?? null,
        ];
    }

    public function decide(Request $request, string $token): JsonResponse
    {
        [, $jobCard] = $this->resolve($token);

        $data = $request->validate([
            'decisions' => 'required|array|min:1|max:100',
            'decisions.*.line_key' => 'required|uuid',
            'decisions.*.decision' => ['required', Rule::in([Line::APPROVED, Line::DECLINED])],
            'comment' => 'nullable|string|max:2000',
        ]);

        $this->decisions->decide(
            $jobCard,
            collect($data['decisions'])->pluck('decision', 'line_key')->all(),
            Line::VIA_CUSTOMER,
            null,
            null,
            $request->ip(),
        );

        if (!empty($data['comment'])) {
            $this->addComment($jobCard, $data['comment']);
        }

        return $this->show($token);
    }

    public function comment(Request $request, string $token): JsonResponse
    {
        [, $jobCard] = $this->resolve($token);
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $this->addComment($jobCard, $data['body']);

        return $this->show($token);
    }

    public function estimatePdf(string $token, CustomerDocumentService $documents)
    {
        [, $jobCard] = $this->resolve($token);
        $estimate = $this->sentEstimate($jobCard) ?? throw new EstimateException('No estimate yet.', [], 404);

        return $documents->estimatePdf($jobCard, $estimate, forCustomer: true);
    }

    public function reportPdf(string $token, CustomerDocumentService $documents)
    {
        [, $jobCard] = $this->resolve($token);

        if (!$this->reports->settings($jobCard)->released_at) {
            throw new EstimateException('The service report is not available yet.', [], 404);
        }

        return $documents->reportPdf($jobCard, forCustomer: true);
    }

    /**
     * @return array{0: CustomerLink, 1: JobCard}
     */
    private function resolve(string $token): array
    {
        $link = $this->links->resolve($token);
        // Branch scope does not apply: the customer is not a staff user
        $jobCard = $link ? JobCard::withoutGlobalScopes()->find($link->job_card_id) : null;

        if (!$link || !$jobCard) {
            throw new EstimateException('This link is not valid any more. Please ask the workshop for a new one.', [], 404);
        }

        return [$link, $jobCard];
    }

    private function sentEstimate(JobCard $jobCard): ?JobCardEstimate
    {
        return JobCardEstimate::where('job_card_id', $jobCard->id)
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->latest('version')
            ->first();
    }

    private function addComment(JobCard $jobCard, string $body): void
    {
        $estimate = $this->sentEstimate($jobCard) ?? throw new EstimateException('No estimate to comment on yet.', [], 404);

        $estimate->comments()->create([
            'author_type' => JobCardEstimateComment::AUTHOR_CUSTOMER,
            'body' => $body,
        ]);
    }
}
