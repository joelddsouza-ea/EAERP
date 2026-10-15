<?php

namespace App\Modules\Billing\Services;

use App\Core\Models\User;
use App\Modules\Billing\Models\GatePass;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\Payment;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine as Line;
use App\Modules\Workshop\Services\CustomerMessageService;
use App\Modules\Workshop\Services\JobLifecycleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A job's money, from first advance to the car leaving:
 *  - payments taken against the job at any time (advance, during work, final);
 *  - the proforma (final bill) built from the approved estimate, GST-inclusive, which
 *    back office can trim and add "other items" to, then approves;
 *  - the proforma is converted into a GST tax invoice, or closed as it is once paid;
 *  - the gate pass is issued when the bill is paid (or by an owner/manager override),
 *    and "Vehicle out" on it delivers the job.
 */
class BillingService
{
    public const SAC_SERVICE = '998714';
    public const HSN_PARTS = '8708';
    public const GST_RATES = [0, 5, 12, 18, 28];

    public function __construct(protected CustomerMessageService $messages) {}

    // ------------------------------------------------------------------ reading

    /**
     * The job's current bill: its tax invoice, else its proforma (cancelled ones ignored).
     */
    public function currentBill(JobCard $jobCard): ?Invoice
    {
        $bills = Invoice::withoutGlobalScopes()
            ->where('job_card_id', $jobCard->id)
            ->where('status', '!=', Invoice::CANCELLED)
            ->orderByDesc('id')
            ->get();

        return $bills->firstWhere('kind', Invoice::KIND_TAX) ?? $bills->firstWhere('kind', Invoice::KIND_PROFORMA);
    }

    /**
     * The bill the customer is asked to pay (approved proforma or tax invoice), if any.
     */
    public function payableBill(JobCard $jobCard): ?Invoice
    {
        $bill = $this->currentBill($jobCard);

        return $bill && $bill->isPayable() ? $bill : null;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function payments(JobCard $jobCard): Collection
    {
        return Payment::withoutGlobalScopes()
            ->where('job_card_id', $jobCard->id)
            ->where('status', 'completed')
            ->with('receivedBy:id,name')
            ->orderBy('id')
            ->get();
    }

    public function gatePass(JobCard $jobCard): ?GatePass
    {
        return GatePass::where('job_card_id', $jobCard->id)->active()->latest('id')->first();
    }

    /**
     * Everything the job card's Billing & Delivery card shows.
     */
    public function summary(JobCard $jobCard, ?User $user = null): array
    {
        $bill = $this->currentBill($jobCard);
        $payable = $bill && $bill->isPayable() ? $bill : null;
        $payments = $this->payments($jobCard);
        $paid = round((float) $payments->sum('amount'), 2);
        $gatePass = $this->gatePass($jobCard);
        $estimate = $this->sentEstimate($jobCard);

        return [
            'estimate_total' => $estimate ? (float) $estimate->grand_total : null,
            'bill' => $bill ? $this->presentBill($bill, $estimate) : null,
            'bill_total' => $payable ? (float) $payable->total_amount : null,
            'paid' => $paid,
            'balance' => $payable ? round((float) $payable->total_amount - $paid, 2) : -$paid,
            'payments' => $payments->map(fn (Payment $p) => $this->presentPayment($p))->values(),
            'gate_pass' => $gatePass ? $this->presentGatePass($gatePass) : null,
            'proforma_from' => $bill ? null : $this->proformaBlocker($jobCard),
            'methods' => Payment::METHODS,
            'gst_rates' => self::GST_RATES,
            'can' => $user ? [
                'manage' => $user->hasPermission('billing.proforma.manage'),
                'approve' => $user->hasPermission('billing.proforma.approve'),
                'pay' => $user->hasPermission('billing.payments.create'),
                'override_gate_pass' => $user->hasPermission('workshop.job-cards.override-guards'),
                'vehicle_out' => $user->hasAnyPermission(['workshop.job-cards.change-status', 'billing.payments.create']),
            ] : null,
        ];
    }

    // ---------------------------------------------------------------- payments

    /**
     * Money received on the job: an advance (no bill yet), a part payment, or the final one.
     */
    public function recordPayment(JobCard $jobCard, array $data, User $user): Payment
    {
        if ($jobCard->status === JobCardStatus::Cancelled->value) {
            throw new BillingException('This job is cancelled.');
        }

        $amount = round((float) $data['amount'], 2);
        $bill = $this->payableBill($jobCard);
        $balance = $bill ? round((float) $bill->total_amount - (float) $this->payments($jobCard)->sum('amount'), 2) : null;

        if ($bill && $amount > $balance + 0.001) {
            throw new BillingException($balance > 0
                ? 'That is more than the balance of Rs ' . number_format($balance, 2) . '.'
                : 'The bill is already paid in full.');
        }

        $payment = DB::transaction(fn () => Payment::create([
            'branch_id' => $jobCard->branch_id,
            'job_card_id' => $jobCard->id,
            'customer_id' => $jobCard->customer_id,
            'invoice_id' => $bill?->id,
            'payment_number' => Payment::generatePaymentNumber($jobCard->branch_id),
            'amount' => $amount,
            'stage' => !$bill ? Payment::STAGE_ADVANCE : ($amount >= $balance - 0.001 ? Payment::STAGE_FINAL : Payment::STAGE_PART),
            'method' => $data['method'],
            'reference_number' => $data['reference_number'] ?? null,
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'cheque_date' => $data['cheque_date'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'status' => 'completed',
            'received_by' => $user->id,
        ]));

        $this->messages->prepare($jobCard, 'payment_received', [
            'amount' => number_format($amount, 0),
            'receipt' => $payment->payment_number,
            'balance' => number_format(max(0, $bill ? $balance - $amount : 0), 0),
        ], $user);

        app(JobLifecycleService::class)->sync($jobCard->fresh(), 'payment');

        return $payment;
    }

    // ---------------------------------------------------------------- proforma

    /**
     * Why a proforma can't be made yet, or null when it can.
     */
    public function proformaBlocker(JobCard $jobCard): ?string
    {
        if ($jobCard->isClosed()) {
            return 'The job is closed.';
        }

        $estimate = $this->sentEstimate($jobCard);
        if (!$estimate) {
            return 'Send the estimate to the customer first: the bill is made from it.';
        }

        $billable = [JobCardStatus::ReadyForDelivery->value, JobCardStatus::Invoiced->value, JobCardStatus::GatePassGenerated->value];
        if (!in_array($jobCard->status, $billable, true)) {
            return 'The final bill is made after the final QC, when the car is Ready for Delivery. Advances can be taken any time.';
        }

        $pending = $estimate->lines->where('approval_status', Line::PENDING)->count();

        return $pending ? "The customer has not answered {$pending} estimate item(s) yet." : null;
    }

    public function createProforma(JobCard $jobCard, User $user): Invoice
    {
        if ($blocker = $this->proformaBlocker($jobCard)) {
            throw new BillingException($blocker);
        }
        if ($this->currentBill($jobCard)) {
            throw new BillingException('This job already has a bill.');
        }

        $estimate = $this->sentEstimate($jobCard);

        return DB::transaction(function () use ($jobCard, $estimate, $user) {
            $bill = Invoice::create([
                'branch_id' => $jobCard->branch_id,
                'job_card_id' => $jobCard->id,
                'customer_id' => $jobCard->customer_id,
                'estimate_id' => $estimate->id,
                'kind' => Invoice::KIND_PROFORMA,
                'invoice_number' => Invoice::generateInvoiceNumber($jobCard->branch_id, 'PRF'),
                'invoice_date' => now()->toDateString(),
                'status' => Invoice::DRAFT,
                'is_igst' => false,
                'place_of_supply' => config('workshop.billing.place_of_supply'),
                'customer_gstin' => $jobCard->customer?->gstin,
                'terms' => config('workshop.billing.terms'),
                'created_by' => $user->id,
            ]);

            $this->addEstimateLines($bill, $estimate);

            return $bill->fresh('items');
        });
    }

    /**
     * Rebuild a draft proforma's estimate lines after the estimate changed (other items stay).
     */
    public function refreshProforma(Invoice $bill): Invoice
    {
        $this->assertProforma($bill, [Invoice::DRAFT]);
        $jobCard = $bill->jobCard()->withoutGlobalScopes()->firstOrFail();

        if ($blocker = $this->proformaBlocker($jobCard)) {
            throw new BillingException($blocker);
        }

        $estimate = $this->sentEstimate($jobCard);

        return DB::transaction(function () use ($bill, $estimate) {
            $bill->items()->whereNotNull('estimate_line_key')->get()->each->delete();
            $bill->update(['estimate_id' => $estimate->id]);
            $this->addEstimateLines($bill, $estimate);

            return $bill->fresh('items');
        });
    }

    public function discardProforma(Invoice $bill): void
    {
        $this->assertProforma($bill, [Invoice::DRAFT]);

        DB::transaction(function () use ($bill) {
            $bill->items()->delete();
            $bill->update(['status' => Invoice::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'Draft discarded']);
        });
    }

    /**
     * An "other item": priced before GST, with GST yes/no and the rate.
     */
    public function addItem(Invoice $bill, array $data): InvoiceItem
    {
        $this->assertEditable($bill);
        $gst = (bool) ($data['gst_applicable'] ?? true);

        return $bill->items()->create([
            'section' => 'other',
            'type' => $data['type'] ?? 'other',
            'hsn_code' => $data['hsn_code'] ?? (($data['type'] ?? 'other') === 'part' ? self::HSN_PARTS : self::SAC_SERVICE),
            'description' => $data['description'],
            'quantity' => $data['quantity'] ?? 1,
            'unit' => 'nos',
            'unit_price' => $data['unit_price'],
            'discount' => $data['discount'] ?? 0,
            'gst_applicable' => $gst,
            'gst_inclusive' => false,
            'tax_rate' => $gst ? (float) ($data['tax_rate'] ?? 18) : 0,
            'sort_order' => (int) $bill->items()->max('sort_order') + 1,
        ]);
    }

    public function updateItemHsn(Invoice $bill, InvoiceItem $item, string $hsn): void
    {
        $this->assertEditable($bill);

        if ($item->invoice_id !== $bill->id) {
            throw new BillingException('That line is not on this bill.', [], 404);
        }

        $item->update(['hsn_code' => $hsn]);
    }

    public function removeItem(Invoice $bill, InvoiceItem $item): void
    {
        $this->assertEditable($bill);

        if ($item->invoice_id !== $bill->id) {
            throw new BillingException('That line is not on this bill.', [], 404);
        }
        if ($bill->items()->count() <= 1) {
            throw new BillingException('A bill needs at least one line.');
        }

        $item->delete();
        $this->refreshPayments($bill);
    }

    /**
     * Back office approves the bill: the customer is asked to pay it.
     */
    public function approve(Invoice $bill, User $user): Invoice
    {
        $this->assertProforma($bill, [Invoice::DRAFT]);

        if (!$bill->items()->exists()) {
            throw new BillingException('The bill has no lines.');
        }

        DB::transaction(function () use ($bill, $user) {
            $bill->update(['status' => Invoice::APPROVED, 'approved_by' => $user->id, 'approved_at' => now()]);
            $this->refreshPayments($bill);
        });

        app(JobLifecycleService::class)->sync($bill->jobCard()->withoutGlobalScopes()->firstOrFail(), 'bill_approved');

        return $bill->fresh('items');
    }

    /**
     * GST tax invoice from the approved proforma (lines deleted from it stay out).
     */
    public function convertToTaxInvoice(Invoice $proforma, array $data, User $user): Invoice
    {
        $this->assertProforma($proforma, [Invoice::APPROVED]);

        $exclude = array_map('intval', $data['exclude_item_ids'] ?? []);
        if ($exclude) {
            $lines = $proforma->items()->get();
            if ($lines->whereNotIn('id', $exclude)->isEmpty()) {
                throw new BillingException('Keep at least one line on the tax invoice.');
            }
            DB::transaction(function () use ($lines, $exclude, $proforma) {
                $lines->whereIn('id', $exclude)->each->delete();
                $this->refreshPayments($proforma);
            });
            $proforma->refresh();
        }

        $invoice = DB::transaction(function () use ($proforma, $data, $user) {
            $isIgst = (bool) ($data['is_igst'] ?? $proforma->is_igst);

            $invoice = Invoice::create([
                'branch_id' => $proforma->branch_id,
                'job_card_id' => $proforma->job_card_id,
                'customer_id' => $proforma->customer_id,
                'estimate_id' => $proforma->estimate_id,
                'proforma_id' => $proforma->id,
                'kind' => Invoice::KIND_TAX,
                'invoice_number' => Invoice::generateInvoiceNumber($proforma->branch_id),
                'invoice_date' => now()->toDateString(),
                'status' => Invoice::ISSUED,
                'issued_at' => now(),
                'is_igst' => $isIgst,
                'place_of_supply' => $data['place_of_supply'] ?? $proforma->place_of_supply,
                'customer_gstin' => $data['customer_gstin'] ?? $proforma->customer_gstin,
                'terms' => $proforma->terms,
                'notes' => $proforma->notes,
                'created_by' => $user->id,
                'approved_by' => $proforma->approved_by,
                'approved_at' => $proforma->approved_at,
            ]);

            foreach ($proforma->items as $item) {
                $invoice->items()->create($item->only([
                    'estimate_line_key', 'section', 'type', 'hsn_code', 'description', 'quantity', 'unit', 'unit_price',
                    'discount', 'tax_rate', 'gst_inclusive', 'gst_applicable', 'sort_order',
                ]));
            }

            $proforma->update(['status' => Invoice::CONVERTED, 'closed_by' => $user->id, 'closed_at' => now()]);

            Payment::withoutGlobalScopes()->where('job_card_id', $proforma->job_card_id)->update(['invoice_id' => $invoice->id]);
            $invoice->refresh()->updatePaymentStatus();

            return $invoice;
        });

        app(JobLifecycleService::class)->sync($invoice->jobCard()->withoutGlobalScopes()->firstOrFail(), 'tax_invoice');

        return $invoice->fresh('items');
    }

    /**
     * Finish billing on the paid proforma, without a tax invoice.
     */
    public function closeOnProforma(Invoice $proforma, User $user, ?string $note = null): Invoice
    {
        $this->assertProforma($proforma, [Invoice::APPROVED]);
        $proforma->updatePaymentStatus();

        if ((float) $proforma->balance_amount > 0) {
            throw new BillingException('Rs ' . number_format((float) $proforma->balance_amount, 2) . ' is still to be paid. Close the bill once it is paid in full.');
        }

        $proforma->update([
            'status' => Invoice::CLOSED,
            'closed_by' => $user->id,
            'closed_at' => now(),
            'close_note' => $note,
        ]);

        app(JobLifecycleService::class)->sync($proforma->jobCard()->withoutGlobalScopes()->firstOrFail(), 'bill_closed');

        return $proforma->fresh('items');
    }

    // --------------------------------------------------------------- gate pass

    /**
     * Issued by itself once the job is invoiced and the bill is paid in full.
     */
    public function issueGatePassIfPaid(JobCard $jobCard): ?GatePass
    {
        if ($jobCard->status !== JobCardStatus::Invoiced->value || $this->gatePass($jobCard)) {
            return null;
        }

        $bill = $this->payableBill($jobCard);
        if (!$bill) {
            return null;
        }

        $bill->updatePaymentStatus();

        return (float) $bill->balance_amount <= 0 ? $this->issueGatePass($jobCard, $bill, null) : null;
    }

    /**
     * Owner/manager lets the car go before full payment, with a reason.
     */
    public function overrideGatePass(JobCard $jobCard, User $user, string $reason): GatePass
    {
        if ($this->gatePass($jobCard)) {
            throw new BillingException('A gate pass is already issued.');
        }
        if (!in_array($jobCard->status, [JobCardStatus::ReadyForDelivery->value, JobCardStatus::Invoiced->value], true)) {
            throw new BillingException('A gate pass can be issued once the car is ready for delivery.');
        }

        $bill = $this->payableBill($jobCard);
        if (!$bill) {
            throw new BillingException('Approve the final bill first.');
        }

        $bill->updatePaymentStatus();
        $pass = $this->issueGatePass($jobCard, $bill, $user, $reason);

        app(JobLifecycleService::class)->sync($jobCard->fresh(), 'gate_pass_override');

        return $pass;
    }

    /**
     * The car leaves: the job is delivered.
     */
    public function vehicleOut(JobCard $jobCard, User $user): GatePass
    {
        $pass = $this->gatePass($jobCard) ?? throw new BillingException('No gate pass: the car cannot leave yet.');

        if ($pass->vehicle_out_at) {
            throw new BillingException('The car has already left.');
        }

        $pass->update(['vehicle_out_at' => now(), 'vehicle_out_by' => $user->id]);

        app(JobLifecycleService::class)->sync($jobCard->fresh(), 'vehicle_out');

        return $pass;
    }

    protected function issueGatePass(JobCard $jobCard, Invoice $bill, ?User $user, ?string $reason = null): GatePass
    {
        $due = max(0, round((float) $bill->balance_amount, 2));

        return GatePass::create([
            'branch_id' => $jobCard->branch_id,
            'job_card_id' => $jobCard->id,
            'invoice_id' => $bill->id,
            'number' => GatePass::generateNumber($jobCard->branch_id),
            'amount_due' => $due,
            'override' => $due > 0,
            'override_reason' => $due > 0 ? $reason : null,
            'issued_by' => $user?->id,
            'issued_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- presenting

    public function presentBill(Invoice $bill, ?JobCardEstimate $estimate = null): array
    {
        $bill->loadMissing(['items', 'approvedBy:id,name']);

        return [
            'id' => $bill->id,
            'number' => $bill->invoice_number,
            'kind' => $bill->kind,
            'status' => $bill->status,
            'date' => $bill->invoice_date?->toDateString(),
            'editable' => $bill->isEditable(),
            'payable' => $bill->isPayable(),
            'is_igst' => $bill->is_igst,
            'customer_gstin' => $bill->customer_gstin,
            'place_of_supply' => $bill->place_of_supply,
            'lines' => $bill->items->map(fn (InvoiceItem $i) => [
                'id' => $i->id,
                'type' => $i->type,
                'hsn_code' => $i->hsn_code,
                'description' => $i->description,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'taxable_amount' => (float) $i->taxable_amount,
                'tax_rate' => (float) $i->tax_rate,
                'gst_inclusive' => $i->gst_inclusive,
                'gst_applicable' => $i->gst_applicable,
                'tax' => round((float) $i->cgst + (float) $i->sgst + (float) $i->igst, 2),
                'total' => (float) $i->total,
                'from_estimate' => $i->estimate_line_key !== null,
                'section' => $i->section ?? ($i->estimate_line_key ? 'additional' : 'other'),
            ])->values(),
            'taxable_amount' => (float) $bill->taxable_amount,
            'cgst' => (float) $bill->cgst,
            'sgst' => (float) $bill->sgst,
            'igst' => (float) $bill->igst,
            'total_tax' => (float) $bill->total_tax,
            'round_off' => $bill->roundOff(),
            'total' => (float) $bill->total_amount,
            'approved_by' => $bill->approvedBy?->name,
            'approved_at' => $bill->approved_at?->toIso8601String(),
            'closed_at' => $bill->closed_at?->toIso8601String(),
            'proforma_number' => $bill->proforma_id ? Invoice::withoutGlobalScopes()->whereKey($bill->proforma_id)->value('invoice_number') : null,
            // The estimate was re-sent after this draft was made
            'estimate_changed' => $bill->status === Invoice::DRAFT && $estimate && $bill->estimate_id !== $estimate->id,
        ];
    }

    public function presentPayment(Payment $p): array
    {
        return [
            'id' => $p->id,
            'number' => $p->payment_number,
            'amount' => (float) $p->amount,
            'stage' => $p->stage,
            'method' => $p->method,
            'reference_number' => $p->reference_number,
            'payment_date' => $p->payment_date?->toDateString(),
            'notes' => $p->notes,
            'received_by' => $p->receivedBy?->name,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    public function presentGatePass(GatePass $pass): array
    {
        $pass->loadMissing(['issuedBy:id,name', 'vehicleOutBy:id,name']);

        return [
            'id' => $pass->id,
            'number' => $pass->number,
            'override' => $pass->override,
            'override_reason' => $pass->override_reason,
            'amount_due' => (float) $pass->amount_due,
            'issued_by' => $pass->issuedBy?->name ?? 'System (paid in full)',
            'issued_at' => $pass->issued_at?->toIso8601String(),
            'vehicle_out_at' => $pass->vehicle_out_at?->toIso8601String(),
            'vehicle_out_by' => $pass->vehicleOutBy?->name,
        ];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every line of the service plan (oil, plan items, labour) and every approved
     * additional line, GST-inclusive with its HSN/SAC. Each block's discount is spread
     * over its lines in proportion, so the bill equals the estimate.
     */
    protected function addEstimateLines(Invoice $bill, JobCardEstimate $estimate): void
    {
        $sort = 0;

        if ($estimate->package_id && (float) $estimate->package_price > 0) {
            $plan = $estimate->lines->filter(fn (Line $l) => !$l->isAdditional() && round((float) $l->amount, 2) != 0.0)->values();
            $sort = $this->addBlock($bill, $plan, (float) $estimate->package_price - (float) $estimate->plan_total, 'plan', $sort);
        }

        $extras = $estimate->lines->filter(fn (Line $l) => $l->isAdditional() && $l->approval_status === Line::APPROVED)->values();
        $base = (float) $extras->sum(fn (Line $l) => (float) $l->amount);
        $this->addBlock($bill, $extras, $base > 0 ? $base - (float) $estimate->extras_total : 0.0, 'additional', $sort);
    }

    /**
     * @param Collection<int, Line> $lines
     */
    protected function addBlock(Invoice $bill, Collection $lines, float $discount, string $section, int $sort): int
    {
        $rate = (float) config('workshop.billing.inclusive_gst_rate', 18);
        $base = (float) $lines->sum(fn (Line $l) => max(0, (float) $l->amount));
        $discountLeft = round(max(0, $discount), 2);
        $lastPositive = $lines->keys()->filter(fn ($k) => (float) $lines[$k]->amount > 0)->last();

        foreach ($lines as $i => $line) {
            $amount = (float) $line->amount;
            $share = 0.0;
            if ($amount > 0 && $base > 0) {
                $share = $i === $lastPositive ? $discountLeft : min($discountLeft, round($amount * $discount / $base, 2));
                $discountLeft = round($discountLeft - $share, 2);
            }

            $hsn = $this->hsnFor($line);
            // The labour balance absorbs the plan's rounding, so it is billed as one amount
            $whole = $line->kind === Line::KIND_LABOUR_BALANCE || (float) $line->quantity == 0.0;

            $bill->items()->create([
                'estimate_line_key' => $line->line_key,
                'section' => $section,
                'type' => str_starts_with($hsn, '99') ? 'labor' : 'part',
                'hsn_code' => $hsn,
                'description' => $line->description,
                'quantity' => $whole ? 1 : $line->quantity,
                'unit' => 'nos',
                'unit_price' => $whole ? $amount : $line->rate,
                'discount' => $share,
                'tax_rate' => $rate,
                'gst_inclusive' => true,
                'gst_applicable' => true,
                'sort_order' => $sort++,
            ]);
        }

        return $sort;
    }

    /**
     * Default HSN/SAC: engine oil, parts by keyword, services and labour as SAC 998714.
     */
    public function hsnFor(Line $line): string
    {
        $services = config('workshop.billing.sac_services', self::SAC_SERVICE);

        if (in_array($line->kind, [Line::KIND_LABOUR_BALANCE, 'labor', 'third_party'], true)) {
            return $services;
        }

        $text = strtolower($line->description);
        foreach (config('workshop.billing.hsn_rules', []) as $words => $code) {
            foreach (explode('|', $words) as $word) {
                if (str_contains($text, $word)) {
                    return $code;
                }
            }
        }

        if ($line->kind === Line::KIND_OIL) {
            return '27101980';
        }

        // Plan items without a part keyword are services (wash, alignment, brake service...)
        return $line->kind === 'part' ? config('workshop.billing.hsn_parts', self::HSN_PARTS) : $services;
    }

    protected function sentEstimate(JobCard $jobCard): ?JobCardEstimate
    {
        return JobCardEstimate::where('job_card_id', $jobCard->id)
            ->where('status', JobCardEstimate::STATUS_SENT)
            ->with('lines')
            ->latest('version')
            ->first();
    }

    protected function refreshPayments(Invoice $bill): void
    {
        if ($bill->isPayable()) {
            Payment::withoutGlobalScopes()->where('job_card_id', $bill->job_card_id)->whereNull('invoice_id')->update(['invoice_id' => $bill->id]);
        }

        $bill->refresh()->updatePaymentStatus();
    }

    protected function assertProforma(Invoice $bill, array $statuses): void
    {
        if (!$bill->isProforma()) {
            throw new BillingException('Only a proforma can do that.');
        }
        if (!in_array($bill->status, $statuses, true)) {
            throw new BillingException("Not possible while the proforma is {$bill->status}.");
        }
    }

    protected function assertEditable(Invoice $bill): void
    {
        if (!$bill->isEditable()) {
            throw new BillingException('This bill can no longer be changed.');
        }
    }
}
