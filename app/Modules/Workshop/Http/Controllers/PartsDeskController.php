<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\SparePartQuote;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\Supplier;
use App\Modules\Workshop\Services\EstimatePresenter;
use App\Modules\Workshop\Services\LabourRateResolver;
use App\Modules\Workshop\Services\PartsQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parts pricing loop. Advisor side: ask for prices, add priced parts to the estimate.
 * Spare parts coordinator side (Parts Desk): supplier quotes, submit, mark ordered.
 */
class PartsDeskController extends ApiController
{
    public function __construct(
        protected PartsQuoteService $parts,
        protected EstimatePresenter $presenter,
    ) {}

    // ---- Advisor side (per job card) ----

    public function forJobCard(JobCard $jobCard): JsonResponse
    {
        // The technician's drafts reach the advisor only after "Submit to SA for Parts"
        $requests = $jobCard->sparePartRequests()
            ->whereNotIn('status', [SparePartRequest::DRAFT, SparePartRequest::CANCELLED])
            ->with(['quotes.supplier', 'requestedBy:id,name', 'quotedBy:id,name', 'additionalJob:id,description', 'issue:id,description'])
            ->latest('id')
            ->get();

        return $this->success($requests->map(fn ($r) => $this->present($r, $jobCard)));
    }

    public function requestPrices(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'parts' => 'required|array|min:1|max:30',
            'parts.*.part_name' => 'required|string|max:255',
            'parts.*.part_number' => 'nullable|string|max:50',
            'parts.*.quantity' => 'nullable|numeric|min:0.01|max:9999',
            'parts.*.unit' => 'nullable|string|max:10',
            'parts.*.note' => 'nullable|string|max:1000',
        ]);

        $requests = $this->parts->requestPrices($jobCard, $data['parts'], $request->user());

        return $this->created($requests, 'Sent to the spare parts coordinator');
    }

    public function cancel(JobCard $jobCard, SparePartRequest $sparePartRequest): JsonResponse
    {
        return $this->success($this->parts->cancel($sparePartRequest), 'Price request cancelled');
    }

    /**
     * Advisor sends parts the technician asked for to the spare parts coordinator.
     */
    public function forward(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        $requests = $this->parts->forwardToCoordinator($jobCard, $data['ids'], $request->user());

        return $this->success(['forwarded' => $requests->count()], 'Sent to the spare parts coordinator');
    }

    // ---- Technician side (job sheet) ----

    public function addTechnicianPart(Request $request, JobCard $jobCard, JobCardAdditionalJob $additionalJob): JsonResponse
    {
        $this->assertTechnician($jobCard, $request);
        $data = $request->validate([
            'part_name' => 'required|string|max:255',
            'part_number' => 'nullable|string|max:50',
            'quantity' => 'nullable|numeric|min:0.01|max:9999',
            'unit' => 'nullable|string|max:10',
            'note' => 'nullable|string|max:1000',
        ]);

        return $this->created($this->parts->addTechnicianPart($jobCard, $additionalJob, $data, $request->user()), 'Part added');
    }

    public function addIssuePart(Request $request, JobCard $jobCard, JobCardIssue $issue): JsonResponse
    {
        $this->assertTechnician($jobCard, $request);
        $data = $request->validate([
            'part_name' => 'required|string|max:255',
            'part_number' => 'nullable|string|max:50',
            'quantity' => 'nullable|numeric|min:0.01|max:9999',
            'unit' => 'nullable|string|max:10',
            'note' => 'nullable|string|max:1000',
        ]);

        return $this->created($this->parts->addIssuePart($jobCard, $issue, $data, $request->user()), 'Part added');
    }

    /**
     * The technician removes a part not yet sent to the advisor.
     */
    public function removeTechnicianPart(Request $request, JobCard $jobCard, SparePartRequest $sparePartRequest): JsonResponse
    {
        $this->assertTechnician($jobCard, $request);
        if ($sparePartRequest->status !== SparePartRequest::DRAFT) {
            return $this->validationError(['part' => ['This part has already been sent to the advisor.']]);
        }

        $sparePartRequest->delete();

        return $this->success(null, 'Part removed');
    }

    /**
     * "Submit to SA for Parts": the technician sends the listed parts to the advisor.
     */
    public function submitToAdvisor(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->assertTechnician($jobCard, $request);

        $sent = $this->parts->submitToAdvisor($jobCard, $request->user());

        return $this->success(['submitted' => $sent->count()], 'Sent to the service advisor');
    }

    private function assertTechnician(JobCard $jobCard, Request $request): void
    {
        abort_unless(
            app(\App\Modules\Workshop\Services\JobSheetService::class)->isAssignedTechnician($jobCard, $request->user()),
            403,
            'Only the technician assigned to this job can ask for parts from the job sheet.'
        );
    }

    public function addToEstimate(Request $request, JobCard $jobCard, JobCardEstimate $estimate): JsonResponse
    {
        $data = $request->validate([
            'spare_part_request_id' => ['required', Rule::exists('spare_part_requests', 'id')->where('job_card_id', $jobCard->id)],
            'margin_pct' => 'nullable|numeric|min:0|max:500',
        ]);

        $this->parts->addToEstimate(
            $estimate,
            SparePartRequest::findOrFail($data['spare_part_request_id']),
            isset($data['margin_pct']) ? (float) $data['margin_pct'] : null,
        );

        return $this->created($this->presenter->present($estimate->fresh()), 'Part added to the estimate');
    }

    /**
     * Labour charges for this job's car type and fuel (from the labour master).
     */
    public function labourCharges(Request $request, JobCard $jobCard, LabourRateResolver $resolver): JsonResponse
    {
        $q = $request->validate(['q' => 'nullable|string|max:100'])['q'] ?? null;

        return $this->success($resolver->forJobCard($jobCard, $q));
    }

    // ---- Spare parts coordinator side ----

    /**
     * tab=requests: parts waiting for prices (and quoted, not yet on an estimate);
     * tab=procure: parts the customer approved, and ordered ones.
     */
    public function desk(Request $request): JsonResponse
    {
        $tab = $request->validate(['tab' => 'nullable|in:requests,procure,all'])['tab'] ?? 'requests';

        $statuses = match ($tab) {
            // Approved, ordered, and received in the last 14 days (so arrivals don't vanish at once)
            'procure' => [SparePartRequest::APPROVED, SparePartRequest::ORDERED, SparePartRequest::RECEIVED],
            // Every part sent to the coordinator, whatever its stage
            'all' => [
                SparePartRequest::REQUESTED, SparePartRequest::QUOTED, SparePartRequest::APPROVED,
                SparePartRequest::DECLINED, SparePartRequest::ORDERED, SparePartRequest::RECEIVED,
            ],
            default => [SparePartRequest::REQUESTED, SparePartRequest::QUOTED],
        };

        $requests = SparePartRequest::whereIn('status', $statuses)
            ->when($tab === 'procure', fn ($q) => $q->where(fn ($w) => $w
                ->where('status', '!=', SparePartRequest::RECEIVED)
                ->orWhere('received_at', '>=', now()->subDays(14))))
            ->when($tab === 'all', fn ($q) => $q->where('updated_at', '>=', now()->subDays(60)))
            ->whereHas('jobCard')
            ->with([
                'jobCard:id,job_number,vehicle_id,service_advisor_id,status',
                'jobCard.vehicle:id,registration_no,make_id,model_id',
                'jobCard.vehicle.make:id,name',
                'jobCard.vehicle.model:id,name',
                'jobCard.serviceAdvisor:id,name',
                'quotes.supplier',
                'requestedBy:id,name',
                'quotedBy:id,name',
                'orderedBy:id,name',
            ])
            ->orderBy('requested_at')
            ->get();

        return $this->success([
            'tab' => $tab,
            'requests' => $requests->map(fn ($r) => $this->present($r, $r->jobCard)),
        ]);
    }

    public function storeQuote(Request $request, SparePartRequest $sparePartRequest): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'required_without:supplier_name', Rule::exists('suppliers', 'id')],
            'supplier_name' => 'nullable|required_without:supplier_id|string|max:255',
            'supplier_phone' => 'nullable|string|max:20',
            'unit_cost' => 'required|numeric|min:0',
            'gst_mode' => ['required', Rule::in(SparePartQuote::GST_MODES)],
            'gst_rate' => 'nullable|numeric|min:0|max:28',
            'availability' => 'nullable|string|max:255',
        ]);

        $this->assertVisible($sparePartRequest);

        return $this->created($this->parts->addQuote($sparePartRequest, $data, $request->user()), 'Quote added');
    }

    public function destroyQuote(SparePartRequest $sparePartRequest, SparePartQuote $quote): JsonResponse
    {
        $this->assertVisible($sparePartRequest);
        $this->parts->removeQuote($sparePartRequest, $quote);

        return $this->success(null, 'Quote removed');
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selections' => 'required|array|min:1',
            'selections.*' => 'required|integer',
        ]);

        foreach (SparePartRequest::whereIn('id', array_keys($data['selections']))->get() as $sparePartRequest) {
            $this->assertVisible($sparePartRequest);
        }

        $requests = $this->parts->submitQuotes($data['selections'], $request->user());

        return $this->success(['submitted' => $requests->count()], 'Prices sent to the advisor');
    }

    public function markOrdered(Request $request, SparePartRequest $sparePartRequest): JsonResponse
    {
        $this->assertVisible($sparePartRequest);

        return $this->success($this->parts->markOrdered($sparePartRequest, $request->user()), 'Marked as ordered');
    }

    public function markReceived(Request $request, SparePartRequest $sparePartRequest): JsonResponse
    {
        $this->assertVisible($sparePartRequest);

        return $this->success(
            $this->parts->markReceived($sparePartRequest, $request->user()),
            'Marked as received — the technician has been notified'
        );
    }

    // ---- Suppliers ----

    public function suppliers(): JsonResponse
    {
        return $this->success(Supplier::orderBy('name')->get(['id', 'name', 'contact_person', 'phone', 'gstin', 'is_active']));
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate($this->supplierRules() + ['name' => 'required|string|max:255|unique:suppliers,name']);

        return $this->created(Supplier::create($data + ['is_active' => true]), 'Supplier added');
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate($this->supplierRules() + [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('suppliers', 'name')->ignore($supplier->id)],
            'is_active' => 'sometimes|boolean',
        ]);

        $supplier->update($data);

        return $this->success($supplier, 'Supplier saved');
    }

    private function supplierRules(): array
    {
        return [
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'gstin' => 'nullable|string|max:20',
        ];
    }

    /**
     * The request's job card must be in a branch the user works in (branch scope on JobCard).
     */
    private function assertVisible(SparePartRequest $sparePartRequest): void
    {
        abort_unless(JobCard::whereKey($sparePartRequest->job_card_id)->exists(), 404);
    }

    private function present(SparePartRequest $r, ?JobCard $jobCard): array
    {
        $vehicle = $jobCard?->relationLoaded('vehicle') ? $jobCard->vehicle : null;

        return [
            'id' => $r->id,
            'job_card_id' => $r->job_card_id,
            'job_number' => $jobCard?->job_number,
            'job_status' => $jobCard?->status,
            'vehicle' => $vehicle ? trim(($vehicle->registration_no ?? '') . ' ' . ($vehicle->make?->name ?? '') . ' ' . ($vehicle->model?->name ?? '')) : null,
            'advisor' => $jobCard?->relationLoaded('serviceAdvisor') ? $jobCard->serviceAdvisor?->name : null,
            'part_name' => $r->part_name,
            'part_number' => $r->part_number,
            'quantity' => (float) $r->quantity,
            'unit' => $r->unit,
            'note' => $r->requested_note,
            'status' => $r->status,
            'estimate_line_key' => $r->estimate_line_key,
            'job_card_additional_job_id' => $r->job_card_additional_job_id,
            // The job sheet line the part is for (Additional Issues line, or an older additional job)
            'additional_job' => ($r->relationLoaded('issue') ? $r->issue?->description : null)
                ?? ($r->relationLoaded('additionalJob') ? $r->additionalJob?->description : null),
            'job_card_issue_id' => $r->job_card_issue_id,
            'received_at' => $r->received_at?->toIso8601String(),
            'requested_by' => $r->requestedBy?->name,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'quoted_by' => $r->quotedBy?->name,
            'quoted_at' => $r->quoted_at?->toIso8601String(),
            'ordered_by' => $r->relationLoaded('orderedBy') ? $r->orderedBy?->name : null,
            'ordered_at' => $r->ordered_at?->toIso8601String(),
            'selected_quote_id' => $r->selected_quote_id,
            'quotes' => $r->quotes->map(fn (SparePartQuote $q) => [
                'id' => $q->id,
                'supplier_id' => $q->supplier_id,
                'supplier' => $q->supplier?->name,
                'unit_cost' => (float) $q->unit_cost,
                'gst_mode' => $q->gst_mode,
                'gst_rate' => (float) $q->gst_rate,
                'landed_unit_cost' => (float) $q->landed_unit_cost,
                'availability' => $q->availability,
            ])->values(),
        ];
    }
}
