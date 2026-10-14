<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Services\ExportService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends ApiController
{
    public function __construct(
        protected InvoiceService $invoiceService,
        protected ExportService $exportService
    ) {}

    /**
     * List invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['customer', 'jobCard', 'branch'])
            ->when($request->status, fn($q, $status) =>
                $q->whereIn('status', (array) $status)
            )
            ->when($request->customer_id, fn($q, $id) =>
                $q->where('customer_id', $id)
            )
            ->when($request->date_from, fn($q, $date) =>
                $q->whereDate('invoice_date', '>=', $date)
            )
            ->when($request->date_to, fn($q, $date) =>
                $q->whereDate('invoice_date', '<=', $date)
            )
            ->when($request->search, fn($q, $search) =>
                $q->where(fn($q) =>
                    $q->where('invoice_number', 'like', "%{$search}%")
                      ->orWhereHas('customer', fn($q) => $q->where('name', 'like', "%{$search}%"))
                )
            )
            ->orderBy($request->sort_by ?? 'invoice_date', $request->sort_order ?? 'desc');

        $invoices = $query->paginate($request->per_page ?? 15);

        return $this->paginated($invoices);
    }

    /**
     * Create invoice manually.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'is_igst' => 'boolean',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $branchId = app('branch.context')->getCurrentBranchId();

        $invoice = Invoice::create([
            'branch_id' => $branchId,
            'customer_id' => $request->customer_id,
            'invoice_number' => Invoice::generateInvoiceNumber($branchId),
            'invoice_date' => $request->invoice_date,
            'due_date' => $request->due_date,
            'is_igst' => $request->boolean('is_igst'),
            'notes' => $request->notes,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        foreach ($request->items as $item) {
            $this->invoiceService->addInvoiceItem($invoice, $item);
        }

        $invoice->calculateTotals();

        return $this->created(
            new InvoiceResource($invoice->load(['items', 'customer'])),
            'Invoice created successfully'
        );
    }

    /**
     * Get specific invoice.
     */
    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load(['items', 'customer', 'jobCard.vehicle', 'payments', 'branch', 'createdBy']);

        return $this->success(new InvoiceResource($invoice));
    }

    /**
     * Update invoice.
     */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be updated', 400);
        }

        $request->validate([
            'invoice_date' => 'sometimes|date',
            'due_date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $invoice->update($request->only(['invoice_date', 'due_date', 'discount', 'notes']));

        if ($request->has('discount')) {
            $invoice->calculateTotals();
        }

        return $this->success(
            new InvoiceResource($invoice->fresh()->load('items')),
            'Invoice updated successfully'
        );
    }

    /**
     * Delete invoice.
     */
    public function destroy(Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Only draft invoices can be deleted', 400);
        }

        $invoice->delete();

        return $this->success(null, 'Invoice deleted successfully');
    }

    /**
     * Create invoice from job card.
     */
    public function createFromJobCard(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'is_igst' => 'boolean',
            'place_of_supply' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        try {
            $invoice = $this->invoiceService->createFromJobCard($jobCard, $request->all());

            return $this->created(
                new InvoiceResource($invoice),
                'Invoice created from job card successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Issue invoice.
     */
    public function issue(Invoice $invoice): JsonResponse
    {
        try {
            $invoice = $this->invoiceService->issueInvoice($invoice);

            return $this->success(
                new InvoiceResource($invoice),
                'Invoice issued successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Cancel invoice.
     */
    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $invoice = $this->invoiceService->cancelInvoice($invoice, $request->reason);

            return $this->success(
                new InvoiceResource($invoice),
                'Invoice cancelled successfully'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Download invoice PDF.
     */
    public function downloadPdf(Invoice $invoice)
    {
        $invoice->load(['items', 'customer', 'jobCard.vehicle', 'branch']);

        return $this->exportService->generateInvoicePdf([
            'invoice' => $invoice,
            'invoice_no' => $invoice->invoice_number,
        ]);
    }

    /**
     * Add item to invoice.
     */
    public function addItem(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Cannot add items to non-draft invoice', 400);
        }

        $request->validate([
            'type' => 'nullable|in:package,labor,part,service,addon,other',
            'description' => 'required|string',
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'hsn_code' => 'nullable|string|max:20',
        ]);

        $item = $this->invoiceService->addInvoiceItem($invoice, $request->all());

        return $this->created($item, 'Item added successfully');
    }

    /**
     * Update invoice item.
     */
    public function updateItem(Request $request, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Cannot update items on non-draft invoice', 400);
        }

        $request->validate([
            'description' => 'sometimes|string',
            'quantity' => 'sometimes|numeric|min:0.01',
            'unit_price' => 'sometimes|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $item->update($request->all());

        return $this->success($item->fresh(), 'Item updated successfully');
    }

    /**
     * Remove invoice item.
     */
    public function removeItem(Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        if ($invoice->status !== 'draft') {
            return $this->error('Cannot remove items from non-draft invoice', 400);
        }

        $item->delete();

        return $this->success(null, 'Item removed successfully');
    }
}
