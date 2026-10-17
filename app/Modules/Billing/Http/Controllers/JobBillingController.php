<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentPart;
use App\Modules\Billing\Services\BillingDocuments;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A job's Billing & Delivery card: payments, proforma → tax invoice / close,
 * gate pass and "Vehicle out".
 */
class JobBillingController extends ApiController
{
    public function __construct(
        protected BillingService $billing,
        protected BillingDocuments $documents,
    ) {}

    public function show(Request $request, JobCard $jobCard): JsonResponse
    {
        return $this->success($this->billing->summary($jobCard, $request->user()));
    }

    /**
     * One receipt: a single method (amount + method) or split over several (parts).
     */
    public function recordPayment(Request $request, JobCard $jobCard): JsonResponse
    {
        $methods = [...Payment::METHODS, Payment::METHOD_CREDIT, PaymentPart::STAFF_COLLECTED];
        $data = $request->validate([
            'parts' => 'sometimes|array|min:1|max:6',
            'parts.*.method' => ['required_with:parts', Rule::in($methods)],
            'parts.*.amount' => 'required_with:parts|numeric|min:0|max:10000000',
            'parts.*.reference_number' => 'nullable|string|max:100',
            'parts.*.cheque_number' => 'nullable|string|max:50',
            'parts.*.bank_name' => 'nullable|string|max:100',
            'parts.*.collected_by' => 'nullable|integer|exists:users,id',
            'amount' => 'required_without:parts|numeric|min:1|max:10000000',
            'method' => ['required_without:parts', Rule::in($methods)],
            'collected_by' => 'nullable|integer|exists:users,id',
            'reference_number' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:500',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:50',
            'cheque_date' => 'nullable|date',
            'bank_name' => 'nullable|string|max:100',
        ], ['parts.*.method.in' => 'Choose a payment method for each part.']);

        $data['parts'] ??= [[
            'method' => $data['method'],
            'amount' => $data['amount'],
            'reference_number' => $data['reference_number'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'collected_by' => $data['collected_by'] ?? null,
        ]];

        foreach ($data['parts'] as $i => $part) {
            if ($part['method'] === 'cheque' && empty($part['cheque_number'])) {
                throw new BillingException('Enter the cheque number.', ["parts.{$i}.cheque_number" => ['Enter the cheque number.']]);
            }
            if ($part['method'] === PaymentPart::STAFF_COLLECTED && empty($part['collected_by'])) {
                throw new BillingException('Choose the employee who collected the money.', ["parts.{$i}.collected_by" => ['Who collected it?']]);
            }
        }

        $payment = $this->billing->recordPayment($jobCard, $data, $request->user());

        return $this->created(
            $this->billing->summary($jobCard->fresh(), $request->user()),
            'Payment ' . $payment->payment_number . ' recorded'
        );
    }

    /**
     * Money paid beyond the bill: refunded to the customer, or kept as their credit.
     */
    public function settleExcess(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:refund,credit',
            'amount' => 'nullable|numeric|min:1|max:10000000',
            'method' => ['required_if:mode,refund', 'nullable', Rule::in(Payment::METHODS)],
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ], ['method.required_if' => 'Choose how the money was paid back.']);

        $payment = $this->billing->settleExcess($jobCard, $data, $request->user());

        return $this->success(
            $this->billing->summary($jobCard->fresh(), $request->user()),
            $data['mode'] === 'credit'
                ? 'Rs ' . number_format(-(float) $payment->amount, 2) . " kept as the customer's credit"
                : 'Refund ' . $payment->payment_number . ' recorded'
        );
    }

    public function createProforma(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->billing->createProforma($jobCard, $request->user());

        return $this->created($this->billing->summary($jobCard, $request->user()), 'Proforma created from the estimate');
    }

    public function refreshProforma(Request $request, JobCard $jobCard, Invoice $invoice): JsonResponse
    {
        $this->billing->refreshProforma($this->own($jobCard, $invoice));

        return $this->success($this->billing->summary($jobCard, $request->user()), 'Proforma rebuilt from the latest estimate');
    }

    public function discardProforma(Request $request, JobCard $jobCard, Invoice $invoice): JsonResponse
    {
        $this->billing->discardProforma($this->own($jobCard, $invoice));

        return $this->success($this->billing->summary($jobCard, $request->user()), 'Draft discarded');
    }

    public function addItem(Request $request, JobCard $jobCard, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:255',
            'type' => 'nullable|in:part,labor,third_party,other',
            'quantity' => 'required|numeric|min:0.01|max:9999',
            'unit_price' => 'required|numeric|min:0|max:10000000',
            'gst_applicable' => 'required|boolean',
            'tax_rate' => ['required_if:gst_applicable,true', 'nullable', 'numeric', Rule::in(BillingService::GST_RATES)],
            'hsn_code' => 'nullable|string|max:20',
        ]);

        $this->billing->addItem($this->own($jobCard, $invoice), $data);

        return $this->created($this->billing->summary($jobCard, $request->user()), 'Item added');
    }

    public function updateItem(Request $request, JobCard $jobCard, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        $data = $request->validate(['hsn_code' => ['required', 'string', 'regex:/^[0-9]{4,8}$/']], [
            'hsn_code.regex' => 'An HSN/SAC code has 4 to 8 digits.',
        ]);

        $this->billing->updateItemHsn($this->own($jobCard, $invoice), $item, $data['hsn_code']);

        return $this->success($this->billing->summary($jobCard, $request->user()), 'HSN/SAC saved');
    }

    public function removeItem(Request $request, JobCard $jobCard, Invoice $invoice, InvoiceItem $item): JsonResponse
    {
        $this->billing->removeItem($this->own($jobCard, $invoice), $item);

        return $this->success($this->billing->summary($jobCard, $request->user()), 'Line removed');
    }

    public function approve(Request $request, JobCard $jobCard, Invoice $invoice): JsonResponse
    {
        $this->billing->approve($this->own($jobCard, $invoice), $request->user());

        return $this->success($this->billing->summary($jobCard->fresh(), $request->user()), 'Bill approved');
    }

    public function convert(Request $request, JobCard $jobCard, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'customer_gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'],
            'is_igst' => 'sometimes|boolean',
            'place_of_supply' => 'nullable|string|max:50',
            // Lines left off the tax invoice (removed from the proforma first)
            'exclude_item_ids' => 'sometimes|array',
            'exclude_item_ids.*' => 'integer',
        ], ['customer_gstin.regex' => 'A GSTIN has 15 characters, starting with the 2-digit state code.']);

        $tax = $this->billing->convertToTaxInvoice($this->own($jobCard, $invoice), $data, $request->user());

        return $this->success($this->billing->summary($jobCard->fresh(), $request->user()), 'Tax invoice ' . $tax->invoice_number . ' issued');
    }

    public function close(Request $request, JobCard $jobCard, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        $this->billing->closeOnProforma($this->own($jobCard, $invoice), $request->user(), $data['note'] ?? null);

        return $this->success($this->billing->summary($jobCard->fresh(), $request->user()), 'Bill closed on the proforma');
    }

    public function overrideGatePass(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);

        $this->billing->overrideGatePass($jobCard, $request->user(), $data['reason']);

        return $this->success($this->billing->summary($jobCard->fresh(), $request->user()), 'Gate pass issued');
    }

    public function vehicleOut(Request $request, JobCard $jobCard): JsonResponse
    {
        $this->billing->vehicleOut($jobCard, $request->user());

        return $this->success($this->billing->summary($jobCard->fresh(), $request->user()), 'Vehicle out: job delivered');
    }

    public function billPdf(JobCard $jobCard, Invoice $invoice)
    {
        return $this->documents->billPdf($this->own($jobCard, $invoice));
    }

    public function receiptPdf(JobCard $jobCard, Payment $payment)
    {
        abort_unless($payment->job_card_id === $jobCard->id, 404);

        return $this->documents->receiptPdf($payment);
    }

    public function gatePassPdf(JobCard $jobCard)
    {
        $pass = $this->billing->gatePass($jobCard) ?? throw new BillingException('No gate pass yet.', [], 404);

        return $this->documents->gatePassPdf($pass);
    }

    private function own(JobCard $jobCard, Invoice $invoice): Invoice
    {
        if ($invoice->job_card_id !== $jobCard->id) {
            throw new BillingException('That bill is not on this job.', [], 404);
        }

        return $invoice;
    }
}
