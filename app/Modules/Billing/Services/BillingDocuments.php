<?php

namespace App\Modules\Billing\Services;

use App\Core\Services\ExportService;
use App\Core\Support\IndianCurrency;
use App\Modules\Billing\Models\GatePass;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\CustomerDocumentService;

/**
 * Printable proforma / tax invoice, payment receipt and gate pass (PDF).
 */
class BillingDocuments
{
    private const PDF_OPTIONS = ['enable_font_subsetting' => true];

    public function __construct(
        protected ExportService $export,
        protected CustomerDocumentService $documents,
        protected BillingService $billing,
    ) {}

    public function billPdf(Invoice $bill)
    {
        $jobCard = $this->jobCard($bill->job_card_id);
        $bill->loadMissing(['items', 'customer']);

        return $this->export->toPdf('exports.bill', [
            'company' => $this->documents->company($jobCard),
            'jobCard' => $jobCard,
            'bill' => $bill,
            'paid' => (float) $bill->paid_amount,
            'words' => IndianCurrency::inWords((float) $bill->total_amount),
            'planName' => $bill->estimate_id
                ? \App\Modules\Workshop\Models\JobCardEstimate::whereKey($bill->estimate_id)->value('package_name')
                : null,
        ], ($bill->isProforma() ? 'proforma-' : 'tax-invoice-') . $bill->invoice_number, options: self::PDF_OPTIONS);
    }

    public function receiptPdf(Payment $payment)
    {
        $jobCard = $this->jobCard($payment->job_card_id);
        $payment->loadMissing(['receivedBy', 'parts']);

        return $this->export->toPdf('exports.payment-receipt', [
            'company' => $this->documents->company($jobCard),
            'jobCard' => $jobCard,
            'payment' => $payment,
            'words' => IndianCurrency::inWords((float) $payment->amount),
            'summary' => $this->billing->summary($jobCard),
        ], 'receipt-' . $payment->payment_number, options: self::PDF_OPTIONS);
    }

    public function gatePassPdf(GatePass $pass)
    {
        $jobCard = $this->jobCard($pass->job_card_id);

        return $this->export->toPdf('exports.gate-pass', [
            'company' => $this->documents->company($jobCard),
            'jobCard' => $jobCard,
            'pass' => $pass->loadMissing(['issuedBy', 'vehicleOutBy']),
        ], 'gate-pass-' . $pass->number, options: self::PDF_OPTIONS);
    }

    protected function jobCard(?int $id): JobCard
    {
        return JobCard::withoutGlobalScopes()
            ->with(['customer', 'vehicle.make', 'vehicle.model', 'serviceAdvisor'])
            ->findOrFail($id);
    }
}
