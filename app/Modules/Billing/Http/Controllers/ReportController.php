<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(
        protected InvoiceService $invoiceService
    ) {}

    /**
     * Billing dashboard summary.
     */
    public function dashboard(): JsonResponse
    {
        $today = today();
        $monthStart = $today->copy()->startOfMonth();

        $stats = [
            // Today's stats
            'today' => [
                'collection' => Payment::moneySum(Payment::whereDate('payment_date', $today)),
                'invoices_created' => Invoice::whereDate('created_at', $today)->count(),
                'invoices_paid' => Invoice::whereDate('updated_at', $today)
                    ->where('status', 'paid')
                    ->count(),
            ],

            // Month to date
            'month_to_date' => [
                'total_billing' => Invoice::whereBetween('invoice_date', [$monthStart, $today])
                    ->whereIn('status', ['issued', 'paid', 'partial'])
                    ->sum('total_amount'),
                'total_collection' => Payment::moneySum(Payment::whereBetween('payment_date', [$monthStart, $today])),
                'invoices_count' => Invoice::whereBetween('invoice_date', [$monthStart, $today])
                    ->whereIn('status', ['issued', 'paid', 'partial'])
                    ->count(),
            ],

            // Outstanding
            'outstanding' => [
                'total' => Invoice::unpaid()->sum('balance_amount'),
                'count' => Invoice::unpaid()->count(),
            ],

            // Jobs summary
            'jobs' => [
                'in_progress' => JobCard::active()->count(),
                'ready_for_billing' => JobCard::status('ready_for_delivery')
                    ->whereDoesntHave('invoice', fn($q) => $q->whereNotIn('status', ['cancelled']))
                    ->count(),
            ],
        ];

        return $this->success($stats);
    }

    /**
     * Daily collection report.
     */
    public function dailyCollection(Request $request): JsonResponse
    {
        $date = $request->date ? \Carbon\Carbon::parse($request->date) : today();
        $branchId = $request->branch_id;

        $report = $this->invoiceService->getDailyCollection($date, $branchId);

        return $this->success($report);
    }

    /**
     * Outstanding report.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $branchId = $request->branch_id;

        $report = $this->invoiceService->getOutstandingReport($branchId);

        return $this->success($report);
    }

    /**
     * Invoice register.
     */
    public function invoiceRegister(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $query = Invoice::with(['customer', 'branch'])
            ->dateRange($request->from_date, $request->to_date)
            ->when($request->status, fn($q, $status) =>
                $q->status($status)
            )
            ->when($request->branch_id, fn($q, $branchId) =>
                $q->where('branch_id', $branchId)
            )
            ->orderBy('invoice_date')
            ->orderBy('invoice_number');

        $invoices = $query->get();

        $summary = [
            'total_invoices' => $invoices->count(),
            'total_amount' => $invoices->sum('total_amount'),
            'total_paid' => $invoices->sum('paid_amount'),
            'total_outstanding' => $invoices->sum('balance_amount'),
            'total_cgst' => $invoices->sum('cgst'),
            'total_sgst' => $invoices->sum('sgst'),
            'total_igst' => $invoices->sum('igst'),
            'by_status' => $invoices->groupBy('status')->map(fn($group) => [
                'count' => $group->count(),
                'amount' => $group->sum('total_amount'),
            ]),
        ];

        return $this->success([
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'summary' => $summary,
            'invoices' => $invoices,
        ]);
    }

    /**
     * Payment summary report.
     */
    public function paymentSummary(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        // Money received less refunds; moves into / out of customer credit are not money
        $query = Payment::with(['invoice.customer', 'receivedBy', 'parts'])
            ->dateRange($request->from_date, $request->to_date)
            ->money()
            ->when($request->branch_id, fn($q, $branchId) =>
                $q->where('branch_id', $branchId)
            )
            ->when($request->method, fn($q, $method) =>
                $q->where('method', $method)
            )
            ->orderBy('payment_date')
            ->orderBy('payment_number');

        $payments = $query->get();

        $summary = [
            'total_payments' => $payments->count(),
            'total_amount' => round(array_sum(Payment::totalsByMethod($payments)), 2),
            // Split receipts counted under each of their methods
            'by_method' => collect(Payment::totalsByMethod($payments))->map(fn ($amount, $method) => [
                'count' => $payments->filter(fn ($p) => $p->parts->contains('method', $method) || $p->method === $method)->count(),
                'amount' => $amount,
            ]),
            'refunds' => $payments->where('stage', Payment::STAGE_REFUND)->sum('amount'),
            'by_date' => $payments->groupBy(fn($p) => $p->payment_date->toDateString())
                ->map(fn($group) => $group->sum('amount')),
        ];

        return $this->success([
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'summary' => $summary,
            'payments' => $payments,
        ]);
    }
}
