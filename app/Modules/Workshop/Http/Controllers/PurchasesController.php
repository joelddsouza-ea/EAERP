<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Models\Payment;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\GoodsReceiptLine as Line;
use App\Modules\Workshop\Models\GoodsReceiptMedia;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\SparePartQuote;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\Supplier;
use App\Modules\Workshop\Models\SupplierPayment;
use App\Modules\Workshop\Services\EstimateException;
use App\Modules\Workshop\Services\GoodsReceiptService;
use App\Modules\Workshop\Support\Purchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Purchases module: the switch, GRNs (receive, return, cancel), the technician's part
 * check, suppliers' dues and payments, and the purchase register.
 */
class PurchasesController extends ApiController
{
    public function __construct(protected GoodsReceiptService $grns) {}

    // ------------------------------------------------------------- settings

    public function settings(): JsonResponse
    {
        return $this->success([
            'enabled' => Purchases::enabled(),
            'min_part_photos' => Purchases::minPartPhotos(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'min_part_photos' => 'sometimes|integer|min:0|max:5',
        ]);
        foreach ($data as $field => $value) {
            WorkshopSettings::set("purchases.{$field}", $field === 'enabled' ? (bool) $value : (int) $value);
        }

        return $this->success([
            'enabled' => Purchases::enabled(),
            'min_part_photos' => Purchases::minPartPhotos(),
        ], Purchases::enabled() ? 'Purchases & stock is on' : 'Purchases & stock is off');
    }

    // -------------------------------------------------------------- receive

    /**
     * What can go on a new GRN: approved / ordered parts of every job (with the chosen
     * quote's supplier and cost), open jobs for third-party services, and suppliers.
     */
    public function receivables(): JsonResponse
    {
        $parts = SparePartRequest::whereIn('status', GoodsReceiptService::RECEIVABLE)
            ->whereHas('jobCard')
            ->with([
                'jobCard:id,job_number,vehicle_id,customer_id,status',
                'jobCard.vehicle:id,registration_no,make_id,model_id',
                'jobCard.vehicle.make:id,name',
                'jobCard.vehicle.model:id,name',
                'jobCard.customer:id,name',
                'selectedQuote.supplier',
            ])
            ->orderBy('job_card_id')
            ->get()
            ->map(fn (SparePartRequest $r) => [
                'id' => $r->id,
                'job_card_id' => $r->job_card_id,
                'job_number' => $r->jobCard?->job_number,
                'car' => $this->car($r->jobCard),
                'customer' => $r->jobCard?->customer?->name,
                'part_name' => $r->part_name,
                'part_number' => $r->part_number,
                'quantity' => (float) $r->quantity,
                'unit' => $r->unit,
                'status' => $r->status,
                'supplier_id' => $r->selectedQuote?->supplier_id,
                'supplier' => $r->selectedQuote?->supplier?->name,
                'unit_cost' => $r->selectedQuote ? (float) $r->selectedQuote->unit_cost : null,
                'gst_mode' => $r->selectedQuote?->gst_mode,
                'gst_rate' => $r->selectedQuote ? (float) $r->selectedQuote->gst_rate : null,
            ]);

        $jobs = JobCard::whereNotIn('status', ['delivered', 'cancelled'])
            ->with(['vehicle:id,registration_no,make_id,model_id', 'vehicle.make:id,name', 'vehicle.model:id,name'])
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'job_number', 'vehicle_id'])
            ->map(fn (JobCard $j) => ['id' => $j->id, 'job_number' => $j->job_number, 'car' => $this->car($j)]);

        // Approved third-party lines on the jobs' current estimates (to tie a service bill to)
        $estimates = JobCardEstimate::whereIn('job_card_id', $jobs->pluck('id'))
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->with(['lines' => fn ($q) => $q->where('kind', 'third_party')->where('approval_status', 'approved')])
            ->get();
        $services = $estimates->flatMap(fn ($e) => $e->lines->map(fn ($l) => [
            'job_card_id' => $e->job_card_id,
            'estimate_line_key' => $l->line_key,
            'description' => $l->description,
        ]))->values();

        return $this->success([
            'parts' => $parts->values(),
            'jobs' => $jobs->values(),
            'third_party_lines' => $services,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone', 'gstin']),
            'min_part_photos' => Purchases::minPartPhotos(),
            'gst_modes' => SparePartQuote::GST_MODES,
            'bill_types' => GoodsReceipt::BILL_TYPES,
        ]);
    }

    /**
     * Post a GRN. Multipart: "payload" is the GRN as JSON; bill_photos[] the supplier's
     * bill; line_photos[i][] the photos of line i.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->input('payload'), true);
        $data = Validator::make(is_array($payload) ? $payload : [], [
            'supplier_id' => ['nullable', 'required_without:supplier_name', Rule::exists('suppliers', 'id')],
            'supplier_name' => 'nullable|required_without:supplier_id|string|max:255',
            'supplier_phone' => 'nullable|string|max:20',
            'supplier_bill_no' => 'nullable|required_unless:bill_type,no_bill|string|max:60',
            'bill_date' => 'required|date|before_or_equal:today',
            'bill_type' => ['required', Rule::in(GoodsReceipt::BILL_TYPES)],
            'payment_mode' => 'required|in:cash,credit',
            'notes' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:60',
            'lines.*.kind' => ['required', Rule::in(Line::KINDS)],
            'lines.*.spare_part_request_id' => 'nullable|required_if:lines.*.kind,job_part|integer',
            'lines.*.stock_item_id' => 'nullable|required_if:lines.*.kind,stock|integer',
            'lines.*.job_card_id' => 'nullable|required_if:lines.*.kind,third_party|integer',
            'lines.*.estimate_line_key' => 'nullable|string|max:40',
            'lines.*.description' => 'nullable|required_if:lines.*.kind,third_party|string|max:255',
            'lines.*.part_number' => 'nullable|string|max:100',
            'lines.*.brand' => 'nullable|string|max:100',
            'lines.*.quantity' => 'required|numeric|min:0.01|max:99999',
            'lines.*.unit' => 'nullable|string|max:10',
            'lines.*.mrp' => 'nullable|numeric|min:0',
            'lines.*.unit_cost' => 'required|numeric|min:0|max:10000000',
            'lines.*.gst_mode' => ['required', Rule::in(SparePartQuote::GST_MODES)],
            'lines.*.gst_rate' => 'nullable|numeric|in:0,5,12,18,28',
        ], [
            'supplier_bill_no.required_unless' => 'Enter the supplier bill or challan number.',
        ])->validate();

        $request->validate([
            'bill_photos' => 'sometimes|array|max:5',
            'bill_photos.*' => 'file|mimes:jpg,jpeg,png,webp,heic,pdf|max:15360',
            'line_photos' => 'sometimes|array',
            'line_photos.*' => 'array|max:5',
            'line_photos.*.*' => 'file|mimes:jpg,jpeg,png,webp,heic|max:15360',
        ]);

        $branchId = $this->branchId($request);
        $grn = $this->grns->post($data, $branchId, $request->user(), $request->file('bill_photos', []), $request->file('line_photos', []));

        return $this->created($this->present($grn), "GRN {$grn->grn_number} posted");
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'supplier_id' => 'nullable|integer',
            'status' => 'nullable|in:posted,cancelled',
        ]);
        $from = $data['from'] ?? now()->subDays(30)->toDateString();
        $to = $data['to'] ?? now()->toDateString();

        $grns = GoodsReceipt::with(['supplier:id,name,gstin', 'receivedBy:id,name'])
            ->withCount('lines')
            ->whereBetween('bill_date', [$from, $to])
            // The branch being worked in (all branches when none is chosen)
            ->when(app('branch.context')->getCurrentBranchId(), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($data['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('bill_date')->orderByDesc('id')
            ->get();

        $posted = $grns->where('status', GoodsReceipt::POSTED);

        // Purchase register: what was bought, with and without GST bills
        return $this->success([
            'from' => $from,
            'to' => $to,
            'grns' => $grns->map(fn (GoodsReceipt $g) => $this->summary($g))->values(),
            'totals' => [
                'count' => $posted->count(),
                'taxable' => round((float) $posted->sum('taxable_amount'), 2),
                'gst' => round((float) $posted->sum('gst_amount'), 2),
                'total' => round((float) $posted->sum('total_amount'), 2),
                'with_gst_bill' => round((float) $posted->where('bill_type', GoodsReceipt::BILL_GST)->sum('total_amount'), 2),
                'without_gst_bill' => round((float) $posted->where('bill_type', '!=', GoodsReceipt::BILL_GST)->sum('total_amount'), 2),
                'credit' => round((float) $posted->where('payment_mode', GoodsReceipt::CREDIT)->sum('total_amount'), 2),
            ],
        ]);
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        return $this->success($this->present($goodsReceipt));
    }

    public function cancel(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->success($this->present($this->grns->cancel($goodsReceipt, $data['reason'], $request->user())), 'GRN cancelled');
    }

    public function returnLine(Request $request, Line $line): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $line = $this->grns->returnLine($line, $data['reason'], $request->user());

        return $this->success($this->present($line->receipt()->firstOrFail()), 'Returned to the supplier');
    }

    /**
     * Technician: is this the right part? (Additional Issues line)
     */
    public function verify(Request $request, JobCard $jobCard, Line $line): JsonResponse
    {
        abort_unless($line->job_card_id === $jobCard->id, 404);
        $data = $request->validate([
            'right' => 'required|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        $line = $this->grns->verify($line, (bool) $data['right'], $data['note'] ?? null, $request->user());

        return $this->success([
            'line_id' => $line->id,
            'verification' => $line->verification,
        ], $line->verification === Line::VERIFIED ? 'Part accepted' : 'Wrong part reported to the parts coordinator');
    }

    public function streamMedia(Request $request, GoodsReceiptMedia $media): BinaryFileResponse
    {
        $path = $request->boolean('thumb') ? $media->thumbnail_path : $media->file_path;
        $disk = Storage::disk($media->disk);
        abort_unless($path && $disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $request->boolean('thumb') ? 'image/jpeg' : $media->mime,
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    // ------------------------------------------------------------ suppliers

    /**
     * Suppliers with what is owed to each (credit GRNs less payments).
     */
    public function suppliers(): JsonResponse
    {
        $balances = $this->grns->supplierBalances();

        $suppliers = Supplier::orderBy('name')->get(['id', 'name', 'contact_person', 'phone', 'gstin', 'is_active'])
            ->map(fn (Supplier $s) => [
                ...$s->only(['id', 'name', 'contact_person', 'phone', 'gstin', 'is_active']),
                'purchases_on_credit' => $balances[$s->id]['purchases'] ?? 0.0,
                'paid' => $balances[$s->id]['paid'] ?? 0.0,
                'due' => $balances[$s->id]['due'] ?? 0.0,
            ]);

        return $this->success([
            'suppliers' => $suppliers->values(),
            'total_due' => round((float) $suppliers->sum('due'), 2),
            'methods' => Payment::METHODS,
        ]);
    }

    /**
     * One supplier's ledger: GRNs and payments, newest first.
     */
    public function supplier(Supplier $supplier): JsonResponse
    {
        $grns = GoodsReceipt::where('supplier_id', $supplier->id)->with(['lines', 'supplier'])->orderByDesc('bill_date')->orderByDesc('id')->limit(200)->get();
        $payments = SupplierPayment::where('supplier_id', $supplier->id)->with('createdBy:id,name')->orderByDesc('paid_on')->orderByDesc('id')->limit(200)->get();
        $balance = $this->grns->supplierBalances($supplier->id)[$supplier->id] ?? ['purchases' => 0.0, 'paid' => 0.0, 'due' => 0.0];

        return $this->success([
            'supplier' => $supplier->only(['id', 'name', 'contact_person', 'phone', 'gstin']),
            'balance' => $balance,
            'grns' => $grns->map(fn (GoodsReceipt $g) => [
                ...$this->summary($g),
                'cost_after_returns' => round($g->lines->sum(fn (Line $l) => GoodsReceiptService::lineCost($l)), 2),
            ])->values(),
            'payments' => $payments->map(fn (SupplierPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'reference_number' => $p->reference_number,
                'paid_on' => $p->paid_on?->toDateString(),
                'notes' => $p->notes,
                'by' => $p->createdBy?->name,
            ])->values(),
        ]);
    }

    public function paySupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:1|max:10000000',
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference_number' => 'nullable|string|max:100',
            'paid_on' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ]);

        $this->grns->paySupplier($supplier, $data, $this->branchId($request), $request->user());

        return $this->supplier($supplier);
    }

    // ---------------------------------------------------------------- helpers

    private function branchId(Request $request): int
    {
        $branchId = app('branch.context')->getCurrentBranchId() ?? $request->user()->primaryBranch()?->id;
        if (!$branchId) {
            throw new EstimateException('Choose a branch first.');
        }

        return (int) $branchId;
    }

    private function car(?JobCard $jobCard): ?string
    {
        $v = $jobCard?->vehicle;

        return $v ? trim(($v->registration_no ?? '') . ' ' . ($v->make?->name ?? '') . ' ' . ($v->model?->name ?? '')) : null;
    }

    private function summary(GoodsReceipt $g): array
    {
        return [
            'id' => $g->id,
            'grn_number' => $g->grn_number,
            'supplier' => $g->supplier?->name,
            'supplier_gstin' => $g->supplier?->gstin,
            'supplier_bill_no' => $g->supplier_bill_no,
            'bill_date' => $g->bill_date?->toDateString(),
            'bill_type' => $g->bill_type,
            'payment_mode' => $g->payment_mode,
            'taxable_amount' => (float) $g->taxable_amount,
            'gst_amount' => (float) $g->gst_amount,
            'total_amount' => (float) $g->total_amount,
            'status' => $g->status,
            'lines_count' => $g->lines_count ?? ($g->relationLoaded('lines') ? $g->lines->count() : null),
            'received_by' => $g->relationLoaded('receivedBy') ? $g->receivedBy?->name : null,
        ];
    }

    private function present(GoodsReceipt $g): array
    {
        $g->loadMissing([
            'supplier', 'receivedBy:id,name', 'cancelledBy:id,name', 'media',
            'lines.jobCard:id,job_number,vehicle_id', 'lines.jobCard.vehicle:id,registration_no',
            'lines.stockItem:id,name,unit', 'lines.verifiedBy:id,name',
        ]);

        return [
            ...$this->summary($g),
            'notes' => $g->notes,
            'cancel_reason' => $g->cancel_reason,
            'cancelled_by' => $g->cancelledBy?->name,
            'cancelled_at' => $g->cancelled_at?->toIso8601String(),
            'bill_photos' => $g->media->whereNull('goods_receipt_line_id')->map(fn ($m) => $m->present())->values(),
            'lines' => $g->lines->map(fn (Line $l) => [
                'id' => $l->id,
                'kind' => $l->kind,
                'job_card_id' => $l->job_card_id,
                'job_number' => $l->jobCard?->job_number,
                'registration_no' => $l->jobCard?->vehicle?->registration_no,
                'stock_item' => $l->stockItem?->name,
                'description' => $l->description,
                'part_number' => $l->part_number,
                'brand' => $l->brand,
                'quantity' => (float) $l->quantity,
                'unit' => $l->unit,
                'mrp' => $l->mrp !== null ? (float) $l->mrp : null,
                'unit_cost' => (float) $l->unit_cost,
                'gst_mode' => $l->gst_mode,
                'gst_rate' => (float) $l->gst_rate,
                'taxable_amount' => (float) $l->taxable_amount,
                'gst_amount' => (float) $l->gst_amount,
                'total' => (float) $l->total,
                'verification' => $l->verification,
                'verification_note' => $l->verification_note,
                'verified_by' => $l->verifiedBy?->name,
                'returned' => $l->isReturned(),
                'return_reason' => $l->return_reason,
                'photos' => $g->media->where('goods_receipt_line_id', $l->id)->map(fn ($m) => $m->present())->values(),
            ])->values(),
        ];
    }
}
