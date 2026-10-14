<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\TaxRate;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * Default GST rate.
     */
    protected float $defaultGstRate = 18.00;

    /**
     * Create invoice from job card.
     */
    public function createFromJobCard(JobCard $jobCard, array $options = []): Invoice
    {
        if ($jobCard->status !== 'ready') {
            throw new \InvalidArgumentException('Job card must be in ready status to create invoice');
        }

        return DB::transaction(function () use ($jobCard, $options) {
            $branchId = $jobCard->branch_id;

            // Determine if IGST (inter-state)
            $isIgst = $options['is_igst'] ?? false;

            // Create invoice
            $invoice = Invoice::create([
                'branch_id' => $branchId,
                'job_card_id' => $jobCard->id,
                'customer_id' => $jobCard->customer_id,
                'invoice_number' => Invoice::generateInvoiceNumber($branchId),
                'invoice_date' => now()->toDateString(),
                'due_date' => $options['due_date'] ?? now()->addDays(7)->toDateString(),
                'status' => 'draft',
                'is_igst' => $isIgst,
                'place_of_supply' => $options['place_of_supply'] ?? 'Maharashtra',
                'notes' => $options['notes'] ?? null,
                'terms' => $options['terms'] ?? $this->getDefaultTerms(),
                'created_by' => auth()->id(),
            ]);

            $gstRate = TaxRate::defaultGstRate();

            // Add package as line item
            if ($jobCard->package_id && $jobCard->package_price > 0) {
                $this->addInvoiceItem($invoice, [
                    'type' => 'package',
                    'description' => $jobCard->package->name . ($jobCard->oilType ? ' - ' . $jobCard->oilType->name : ''),
                    'quantity' => 1,
                    'unit_price' => $jobCard->package_price,
                    'tax_rate' => $gstRate,
                    'hsn_code' => '998714', // Maintenance and repair of motor vehicles
                ]);
            }

            // Add addons
            foreach ($jobCard->addons as $addon) {
                $this->addInvoiceItem($invoice, [
                    'type' => 'addon',
                    'description' => $addon->addon_name,
                    'quantity' => 1,
                    'unit_price' => $addon->price,
                    'discount' => $addon->discount,
                    'tax_rate' => $gstRate,
                    'hsn_code' => '998714',
                ]);
            }

            // Add job card items
            foreach ($jobCard->items as $item) {
                $this->addInvoiceItem($invoice, [
                    'type' => $item->type,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'discount' => $item->discount,
                    'tax_rate' => $item->tax_rate ?: $gstRate,
                    'hsn_code' => $this->getHsnCode($item->type),
                ]);
            }

            // Apply job card discount
            if ($jobCard->discount > 0) {
                $invoice->discount = $jobCard->discount;
                $invoice->discount_type = 'fixed';
            }

            // Calculate totals
            $invoice->calculateTotals();

            return $invoice->fresh()->load(['items', 'customer', 'jobCard']);
        });
    }

    /**
     * Add item to invoice.
     */
    public function addInvoiceItem(Invoice $invoice, array $data): InvoiceItem
    {
        return $invoice->items()->create([
            'type' => $data['type'] ?? 'service',
            'hsn_code' => $data['hsn_code'] ?? null,
            'description' => $data['description'],
            'quantity' => $data['quantity'] ?? 1,
            'unit' => $data['unit'] ?? 'nos',
            'unit_price' => $data['unit_price'],
            'discount' => $data['discount'] ?? 0,
            'tax_rate' => $data['tax_rate'] ?? $this->defaultGstRate,
        ]);
    }

    /**
     * Issue invoice.
     */
    public function issueInvoice(Invoice $invoice): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new \InvalidArgumentException('Only draft invoices can be issued');
        }

        $invoice->update([
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        return $invoice;
    }

    /**
     * Cancel invoice.
     */
    public function cancelInvoice(Invoice $invoice, string $reason): Invoice
    {
        if (in_array($invoice->status, ['paid', 'cancelled'])) {
            throw new \InvalidArgumentException('Cannot cancel paid or already cancelled invoice');
        }

        $invoice->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $invoice;
    }

    /**
     * Get HSN code based on item type.
     */
    protected function getHsnCode(string $type): string
    {
        return match ($type) {
            'labor' => '998714', // Maintenance and repair services
            'part' => '8708',    // Motor vehicle parts
            'service' => '998714',
            'third_party' => '998714',
            default => '998714',
        };
    }

    /**
     * Get default terms.
     */
    protected function getDefaultTerms(): string
    {
        return "1. Payment is due upon receipt.\n2. All work is guaranteed for 30 days.\n3. Parts warranty as per manufacturer terms.";
    }

    /**
     * Get daily collection report.
     */
    public function getDailyCollection($date = null, ?int $branchId = null): array
    {
        $date = $date ?? today();

        $query = \App\Modules\Billing\Models\Payment::whereDate('payment_date', $date)
            ->where('status', 'completed');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $payments = $query->with(['invoice.customer', 'receivedBy'])->get();

        $byMethod = $payments->groupBy('method')->map(fn($p) => $p->sum('amount'));

        return [
            'date' => $date->toDateString(),
            'total' => $payments->sum('amount'),
            'by_method' => $byMethod,
            'count' => $payments->count(),
            'payments' => $payments,
        ];
    }

    /**
     * Get outstanding amount report.
     */
    public function getOutstandingReport(?int $branchId = null): array
    {
        $query = Invoice::unpaid()
            ->with(['customer', 'jobCard']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $invoices = $query->get();

        return [
            'total_outstanding' => $invoices->sum('balance_amount'),
            'count' => $invoices->count(),
            'by_age' => [
                '0_30_days' => $invoices->filter(fn($i) => $i->invoice_date >= now()->subDays(30))->sum('balance_amount'),
                '31_60_days' => $invoices->filter(fn($i) => $i->invoice_date < now()->subDays(30) && $i->invoice_date >= now()->subDays(60))->sum('balance_amount'),
                '61_90_days' => $invoices->filter(fn($i) => $i->invoice_date < now()->subDays(60) && $i->invoice_date >= now()->subDays(90))->sum('balance_amount'),
                'over_90_days' => $invoices->filter(fn($i) => $i->invoice_date < now()->subDays(90))->sum('balance_amount'),
            ],
            'invoices' => $invoices,
        ];
    }
}
