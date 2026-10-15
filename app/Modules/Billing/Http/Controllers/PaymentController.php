<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Services\ExportService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends ApiController
{
    public function __construct(
        protected ExportService $exportService
    ) {}

    /**
     * List all payments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['invoice.customer', 'receivedBy'])
            ->when($request->method, fn($q, $method) =>
                $q->where('method', $method)
            )
            ->when($request->date_from, fn($q, $date) =>
                $q->whereDate('payment_date', '>=', $date)
            )
            ->when($request->date_to, fn($q, $date) =>
                $q->whereDate('payment_date', '<=', $date)
            )
            ->when($request->search, fn($q, $search) =>
                $q->where(fn($q) =>
                    $q->where('payment_number', 'like', "%{$search}%")
                      ->orWhere('reference_number', 'like', "%{$search}%")
                )
            )
            ->orderBy($request->sort_by ?? 'payment_date', $request->sort_order ?? 'desc');

        $payments = $query->paginate($request->per_page ?? 15);

        return $this->paginated($payments);
    }

    /**
     * Get specific payment.
     */
    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['invoice.customer', 'receivedBy', 'branch']);

        return $this->success($payment);
    }

    /**
     * Get payments for an invoice.
     */
    public function invoicePayments(Invoice $invoice): JsonResponse
    {
        $payments = $invoice->payments()
            ->with('receivedBy')
            ->orderBy('payment_date', 'desc')
            ->get();

        return $this->success([
            'invoice_number' => $invoice->invoice_number,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'balance_amount' => $invoice->balance_amount,
            'payments' => $payments,
        ]);
    }

    /**
     * Record payment for invoice.
     */
    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        if (!$invoice->isPayable()) {
            return $this->error('Cannot record payment for this invoice status', 400);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,card,upi,bank_transfer,cheque,wallet',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'cheque_number' => 'required_if:method,cheque|string|max:50',
            'cheque_date' => 'required_if:method,cheque|date',
            'bank_name' => 'nullable|string|max:100',
        ]);

        // Validate amount doesn't exceed balance
        if ($request->amount > $invoice->balance_amount) {
            return $this->error('Payment amount cannot exceed balance amount', 422);
        }

        // A job's payments are taken against the job (they count towards its current bill)
        if ($invoice->job_card_id) {
            $jobCard = \App\Modules\Workshop\Models\JobCard::withoutGlobalScopes()->findOrFail($invoice->job_card_id);
            $payment = app(\App\Modules\Billing\Services\BillingService::class)
                ->recordPayment($jobCard, $request->only(['amount', 'method', 'payment_date', 'reference_number', 'notes', 'cheque_number', 'cheque_date', 'bank_name']), $request->user());

            return $this->created([
                'payment' => $payment->load('receivedBy'),
                'invoice' => $invoice->fresh(['customer']),
            ], 'Payment recorded successfully');
        }

        $branchId = app('branch.context')->getCurrentBranchId();

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'branch_id' => $branchId,
            'payment_number' => Payment::generatePaymentNumber($branchId),
            'amount' => $request->amount,
            'method' => $request->method,
            'payment_date' => $request->payment_date,
            'reference_number' => $request->reference_number,
            'notes' => $request->notes,
            'cheque_number' => $request->cheque_number,
            'cheque_date' => $request->cheque_date,
            'bank_name' => $request->bank_name,
            'status' => 'completed',
            'received_by' => auth()->id(),
        ]);

        return $this->created([
            'payment' => $payment->load('receivedBy'),
            'invoice' => $invoice->fresh(['customer']),
        ], 'Payment recorded successfully');
    }

    /**
     * Download payment receipt.
     */
    public function downloadReceipt(Payment $payment)
    {
        $payment->load(['invoice.customer', 'receivedBy', 'branch']);

        return $this->exportService->toPdf(
            'exports.payment-receipt',
            ['payment' => $payment],
            'receipt-' . $payment->payment_number
        );
    }
}
