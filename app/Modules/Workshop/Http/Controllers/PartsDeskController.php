<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\JobCard;
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
        $requests = $jobCard->sparePartRequests()
            ->with(['quotes.supplier', 'requestedBy:id,name', 'quotedBy:id,name'])
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
        $tab = $request->validate(['tab' => 'nullable|in:requests,procure'])['tab'] ?? 'requests';

        $statuses = $tab === 'procure'
            ? [SparePartRequest::APPROVED, SparePartRequest::ORDERED]
            : [SparePartRequest::REQUESTED, SparePartRequest::QUOTED];

        $requests = SparePartRequest::whereIn('status', $statuses)
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
