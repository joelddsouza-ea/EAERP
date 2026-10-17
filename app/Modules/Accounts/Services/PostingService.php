<?php

namespace App\Modules\Accounts\Services;

use App\Core\Support\WorkshopSettings;
use App\Modules\Accounts\Models\BankAccount;
use App\Modules\Accounts\Models\Expense;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Accounts\Models\JournalLine;
use App\Modules\Accounts\Support\ChartOfAccounts as Coa;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentPart;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\GoodsReceiptLine;
use App\Modules\Workshop\Models\StockMovement;
use App\Modules\Workshop\Models\SupplierPayment;
use App\Modules\Workshop\Services\GoodsReceiptService;

/**
 * What each business event does to the books. Every method builds the entry the
 * source should have right now (none when it is a draft, cancelled or before the
 * books start) and hands it to LedgerService::sync, so calling it again is harmless.
 */
class PostingService
{
    public const SOURCES = [
        'invoice' => Invoice::class,
        'payment' => Payment::class,
        'grn' => GoodsReceipt::class,
        'supplier_payment' => SupplierPayment::class,
        'stock_movement' => StockMovement::class,
        'expense' => Expense::class,
        'salary_advance' => SalaryAdvance::class,
        'custody_entry' => StaffCustodyEntry::class,
        'payslip' => Payslip::class,
        'payslip_payment' => Payslip::class,
    ];

    public function __construct(protected LedgerService $ledger) {}

    public function syncSource(string $type, int $id): ?JournalEntry
    {
        return match ($type) {
            'invoice' => $this->invoice($id),
            'payment' => $this->payment($id),
            'grn' => $this->goodsReceipt($id),
            'supplier_payment' => $this->supplierPayment($id),
            'stock_movement' => $this->stockMovement($id),
            'expense' => $this->expense($id),
            'salary_advance' => $this->salaryAdvance($id),
            'custody_entry' => $this->custodyEntry($id),
            'payslip' => $this->payslip($id),
            'payslip_payment' => $this->payslipPayment($id),
            default => throw new \InvalidArgumentException("Unknown posting source {$type}"),
        };
    }

    // ------------------------------------------------------------------ sales

    /**
     * A tax invoice (or a paid proforma closed without one): the customer owes the total;
     * sales by kind of line, output GST, round off.
     */
    public function invoice(int $id): ?JournalEntry
    {
        $inv = Invoice::withoutGlobalScopes()->withTrashed()->with('items')->find($id);
        if (!$inv) {
            return $this->clear('invoice', $id);
        }

        $posts = !$inv->trashed() && (
            ($inv->kind === Invoice::KIND_TAX && in_array($inv->status, [Invoice::ISSUED, Invoice::PARTIAL, Invoice::PAID], true))
            || ($inv->kind === Invoice::KIND_PROFORMA && $inv->status === Invoice::CLOSED)
        );
        $date = $inv->invoice_date?->toDateString() ?? now()->toDateString();

        $lines = [];
        if ($posts && $this->inBooks($date) && (float) $inv->total_amount != 0.0) {
            $customer = $inv->customer_id ? [JournalLine::PARTY_CUSTOMER, (int) $inv->customer_id] : null;
            $lines[] = ['account' => Coa::id(Coa::DEBTORS), 'debit' => (float) $inv->total_amount, 'party' => $customer];

            $taxable = 0.0;
            foreach ($inv->items as $item) {
                $head = match ($item->type) {
                    'part' => Coa::SALES_PARTS,
                    'other' => Coa::SALES_OTHER,
                    default => Coa::SALES_SERVICES,
                };
                $lines[] = ['account' => Coa::id($head), 'credit' => (float) $item->taxable_amount];
                $taxable += (float) $item->taxable_amount;
            }

            $lines[] = ['account' => Coa::id(Coa::OUTPUT_CGST), 'credit' => (float) $inv->cgst];
            $lines[] = ['account' => Coa::id(Coa::OUTPUT_SGST), 'credit' => (float) $inv->sgst];
            $lines[] = ['account' => Coa::id(Coa::OUTPUT_IGST), 'credit' => (float) $inv->igst];
            if ((float) $inv->discount > 0) {
                $lines[] = ['account' => Coa::id(Coa::SALES_OTHER), 'debit' => (float) $inv->discount, 'memo' => 'Bill discount'];
            }

            $roundOff = round((float) $inv->total_amount - ($taxable + (float) $inv->cgst + (float) $inv->sgst + (float) $inv->igst - (float) $inv->discount), 2);
            $lines[] = ['account' => Coa::id(Coa::ROUND_OFF), 'credit' => $roundOff];
        }

        $label = $inv->kind === Invoice::KIND_PROFORMA ? 'Bill (closed on proforma)' : 'Tax invoice';

        return $this->ledger->sync('invoice', $id, 'sale', $date, $inv->branch_id, $lines, "{$label} {$inv->invoice_number}");
    }

    /**
     * Money received from (or paid back to) a customer, per payment method. Customer credit
     * moves stay inside the customer's own balance, so they post nothing.
     */
    public function payment(int $id): ?JournalEntry
    {
        $p = Payment::withoutGlobalScopes()->withTrashed()->with('parts')->find($id);
        if (!$p) {
            return $this->clear('payment', $id);
        }

        $date = $p->payment_date?->toDateString() ?? now()->toDateString();
        $lines = [];

        if (!$p->trashed() && $p->status === 'completed' && $this->inBooks($date)) {
            $customerId = $p->customer_id ?? Invoice::withoutGlobalScopes()->whereKey($p->invoice_id)->value('customer_id');
            $customer = $customerId ? [JournalLine::PARTY_CUSTOMER, (int) $customerId] : null;

            foreach ($p->parts as $part) {
                if ($part->method === Payment::METHOD_CREDIT || (float) $part->amount == 0.0) {
                    continue;
                }
                $amount = (float) $part->amount;
                $lines[] = $this->moneyLine($part, $amount);
                $lines[] = ['account' => Coa::id(Coa::DEBTORS), 'credit' => $amount, 'party' => $customer];
            }
        }

        $type = (float) $p->amount < 0 ? 'refund' : 'receipt';
        $what = $type === 'refund' ? 'Refund' : 'Receipt';

        return $this->ledger->sync('payment', $id, $type, $date, $p->branch_id, $lines, "{$what} {$p->payment_number}");
    }

    /**
     * Debit line for where a customer's money went (negative = paid out).
     */
    protected function moneyLine(PaymentPart $part, float $amount): array
    {
        if ($part->method === 'staff_collected' && $part->collected_by) {
            return ['account' => Coa::id(Coa::STAFF_CUSTODY), 'debit' => $amount, 'party' => [JournalLine::PARTY_USER, (int) $part->collected_by], 'memo' => 'Collected by staff'];
        }

        return ['account' => $this->methodAccount($part->method), 'debit' => $amount, 'memo' => $part->reference_number ? "Ref {$part->reference_number}" : null];
    }

    // -------------------------------------------------------------- purchases

    /**
     * A GRN: parts / services / stock at cost after returns, input GST on GST bills, owed
     * to the supplier (credit) or paid in cash.
     *
     * Stock is carried at the GST-inclusive cost the stock screen uses; the input GST on
     * stock bought on a GST bill is claimed against "oil & consumables used", so the cost
     * of consumables ends up ex-GST once they are issued.
     */
    public function goodsReceipt(int $id): ?JournalEntry
    {
        $grn = GoodsReceipt::with(['lines', 'supplier'])->find($id);
        if (!$grn) {
            return $this->clear('grn', $id);
        }

        $date = $grn->bill_date?->toDateString() ?? $grn->created_at->toDateString();
        $lines = [];

        if ($grn->status === GoodsReceipt::POSTED && $this->inBooks($date)) {
            $gstBill = $grn->bill_type === GoodsReceipt::BILL_GST;
            $total = 0.0;

            foreach ($grn->lines as $line) {
                $cost = GoodsReceiptService::lineCost($line);
                if ($cost <= 0) {
                    continue;
                }
                $total += $cost;
                $share = (float) $line->total > 0 ? $cost / (float) $line->total : 0.0;
                $gst = $gstBill ? round((float) $line->gst_amount * $share, 2) : 0.0;

                if ($line->kind === GoodsReceiptLine::STOCK) {
                    $lines[] = ['account' => Coa::id(Coa::STOCK), 'debit' => $cost, 'memo' => $line->description];
                    if ($gst > 0) {
                        $lines = [...$lines, ...$this->inputGst($gst)];
                        $lines[] = ['account' => Coa::id(Coa::CONSUMABLES_USED), 'credit' => $gst, 'memo' => 'Input GST on stock'];
                    }
                    continue;
                }

                $head = $line->kind === GoodsReceiptLine::THIRD_PARTY ? Coa::THIRD_PARTY : Coa::PURCHASE_PARTS;
                $lines[] = ['account' => Coa::id($head), 'debit' => round($cost - $gst, 2), 'memo' => $line->description];
                $lines = [...$lines, ...$this->inputGst($gst)];
            }

            $total = round($total, 2);
            $lines[] = $grn->payment_mode === GoodsReceipt::CREDIT
                ? ['account' => Coa::id(Coa::CREDITORS), 'credit' => $total, 'party' => [JournalLine::PARTY_SUPPLIER, (int) $grn->supplier_id]]
                : ['account' => Coa::id(Coa::CASH), 'credit' => $total];
        }

        return $this->ledger->sync('grn', $id, 'purchase', $date, $grn->branch_id, $lines, "GRN {$grn->grn_number}" . ($grn->supplier ? " — {$grn->supplier->name}" : ''));
    }

    public function supplierPayment(int $id): ?JournalEntry
    {
        $sp = SupplierPayment::with('supplier')->find($id);
        if (!$sp) {
            return $this->clear('supplier_payment', $id);
        }

        $date = $sp->paid_on?->toDateString() ?? now()->toDateString();
        $lines = !$this->inBooks($date) ? [] : [
            ['account' => Coa::id(Coa::CREDITORS), 'debit' => (float) $sp->amount, 'party' => [JournalLine::PARTY_SUPPLIER, (int) $sp->supplier_id]],
            ['account' => $this->methodAccount($sp->method), 'credit' => (float) $sp->amount, 'memo' => $sp->reference_number ? "Ref {$sp->reference_number}" : null],
        ];

        return $this->ledger->sync('supplier_payment', $id, 'supplier_payment', $date, $sp->branch_id, $lines, 'Paid to ' . ($sp->supplier?->name ?? 'supplier'));
    }

    /**
     * Stock issued to a job, returned from it, or adjusted after a count. Receipts into
     * stock are on their GRN.
     */
    public function stockMovement(int $id): ?JournalEntry
    {
        $m = StockMovement::with('item')->find($id);
        if (!$m) {
            return $this->clear('stock_movement', $id);
        }

        $date = $m->created_at->toDateString();
        $value = round((float) $m->value, 2);
        $isAdjustment = $m->type === StockMovement::ADJUST || ($m->type === StockMovement::IN && !$m->goods_receipt_line_id);
        $lines = [];

        if ($value != 0.0 && $this->inBooks($date)) {
            if (in_array($m->type, [StockMovement::ISSUE, StockMovement::RETURN], true)) {
                $lines = [
                    ['account' => Coa::id(Coa::STOCK), 'debit' => $value],
                    ['account' => Coa::id(Coa::CONSUMABLES_USED), 'credit' => $value],
                ];
            } elseif ($isAdjustment) {
                $lines = [
                    ['account' => Coa::id(Coa::STOCK), 'debit' => $value],
                    ['account' => Coa::id(Coa::STOCK_ADJUSTMENT), 'credit' => $value],
                ];
            }
        }

        $item = $m->item?->name ?? 'stock';

        return $this->ledger->sync('stock_movement', $id, 'stock', $date, $m->branch_id, $lines, ucfirst($isAdjustment ? 'adjustment' : $m->type) . " — {$item}" . ($m->note ? " ({$m->note})" : ''));
    }

    // --------------------------------------------------------------- expenses

    public function expense(int $id): ?JournalEntry
    {
        $e = Expense::with('account')->find($id);
        if (!$e) {
            return $this->clear('expense', $id);
        }

        $date = $e->expense_date->toDateString();
        $lines = [];

        if ($e->status === Expense::POSTED && $this->inBooks($date)) {
            $gst = $e->gst_bill ? round((float) $e->gst_amount, 2) : 0.0;
            $lines[] = ['account' => $e->account_id, 'debit' => round((float) $e->amount - $gst, 2), 'memo' => $e->note];
            $lines = [...$lines, ...$this->inputGst($gst)];
            $lines[] = match ($e->paid_via) {
                'bank' => ['account' => $e->bank_account_id ? (int) BankAccount::whereKey($e->bank_account_id)->value('account_id') : BankAccount::defaultAccountId(), 'credit' => (float) $e->amount],
                'staff_custody' => ['account' => Coa::id(Coa::STAFF_CUSTODY), 'credit' => (float) $e->amount, 'party' => [JournalLine::PARTY_USER, (int) $e->paid_by_user_id]],
                'credit' => ['account' => Coa::id(Coa::CREDITORS), 'credit' => (float) $e->amount, 'party' => [JournalLine::PARTY_SUPPLIER, (int) $e->supplier_id]],
                default => ['account' => Coa::id(Coa::CASH), 'credit' => (float) $e->amount],
            };
        }

        return $this->ledger->sync('expense', $id, 'expense', $date, $e->branch_id, $lines, "Expense {$e->number} — {$e->paid_to}");
    }

    // ---------------------------------------------------------------- payroll

    public function salaryAdvance(int $id): ?JournalEntry
    {
        $a = SalaryAdvance::with('user:id,name')->find($id);
        if (!$a) {
            return $this->clear('salary_advance', $id);
        }

        $date = $a->given_on->toDateString();
        $lines = $a->status === SalaryAdvance::CANCELLED || !$this->inBooks($date) ? [] : [
            ['account' => Coa::id(Coa::SALARY_ADVANCES), 'debit' => (float) $a->amount, 'party' => [JournalLine::PARTY_USER, (int) $a->user_id]],
            ['account' => $this->methodAccount($a->method), 'credit' => (float) $a->amount],
        ];

        return $this->ledger->sync('salary_advance', $id, 'advance', $date, $a->branch_id, $lines, 'Salary advance — ' . ($a->user?->name ?? 'employee'));
    }

    /**
     * Customer money an employee hands over to the cash box or the bank. (Money they
     * collected is on its receipt, money they spent on its expense, recoveries on the payslip.)
     */
    public function custodyEntry(int $id): ?JournalEntry
    {
        $c = StaffCustodyEntry::with('user:id,name')->find($id);
        if (!$c) {
            return $this->clear('custody_entry', $id);
        }

        $date = $c->entry_date->toDateString();
        $lines = $c->kind !== StaffCustodyEntry::HANDED_OVER || !$this->inBooks($date) ? [] : [
            ['account' => $this->methodAccount($c->method ?? 'cash'), 'debit' => -(float) $c->amount],
            ['account' => Coa::id(Coa::STAFF_CUSTODY), 'credit' => -(float) $c->amount, 'party' => [JournalLine::PARTY_USER, (int) $c->user_id]],
        ];

        return $this->ledger->sync('custody_entry', $id, 'custody', $date, $c->branch_id, $lines, 'Customer money handed over by ' . ($c->user?->name ?? 'employee'));
    }

    /**
     * A finalised payslip: salary costs, statutory dues, recoveries, and net pay owed.
     */
    public function payslip(int $id): ?JournalEntry
    {
        $slip = Payslip::with(['lines', 'run', 'user:id,name'])->find($id);
        if (!$slip) {
            return $this->clear('payslip', $id);
        }

        $date = $slip->run->periodEnd();
        $lines = [];

        if (in_array($slip->run->status, ['finalised', 'paid'], true) && $this->inBooks($date)) {
            $user = [JournalLine::PARTY_USER, (int) $slip->user_id];
            foreach ($slip->lines as $l) {
                $amount = (float) $l->amount;
                $line = match ($l->code) {
                    'salary' => ['account' => Coa::id(Coa::SALARIES), 'debit' => $amount],
                    'ot', 'offday' => ['account' => Coa::id(Coa::OVERTIME), 'debit' => $amount],
                    'incentive', 'bonus' => ['account' => Coa::id(Coa::INCENTIVES), 'debit' => $amount],
                    'reimbursement' => ['account' => Coa::id(Coa::STAFF_REIMBURSEMENTS), 'debit' => $amount],
                    'pf_employer', 'esi_employer' => ['account' => Coa::id(Coa::EMPLOYER_CONTRIBUTIONS), 'debit' => $amount],
                    'pf' => ['account' => Coa::id(Coa::PF_PAYABLE), 'credit' => $amount],
                    'esi' => ['account' => Coa::id(Coa::ESI_PAYABLE), 'credit' => $amount],
                    'pt' => ['account' => Coa::id(Coa::PT_PAYABLE), 'credit' => $amount],
                    'advance' => ['account' => Coa::id(Coa::SALARY_ADVANCES), 'credit' => $amount, 'party' => $user],
                    'custody' => ['account' => Coa::id(Coa::STAFF_CUSTODY), 'credit' => $amount, 'party' => $user],
                    default => $l->kind === 'earning'
                        ? ['account' => Coa::id(Coa::SALARIES), 'debit' => $amount]
                        : ['account' => Coa::id(Coa::STAFF_RECOVERIES), 'credit' => $amount],
                };
                $lines[] = [...$line, 'memo' => $l->label];

                // Employer contributions are owed to PF / ESI as well
                if ($l->code === 'pf_employer') {
                    $lines[] = ['account' => Coa::id(Coa::PF_PAYABLE), 'credit' => $amount, 'memo' => $l->label];
                }
                if ($l->code === 'esi_employer') {
                    $lines[] = ['account' => Coa::id(Coa::ESI_PAYABLE), 'credit' => $amount, 'memo' => $l->label];
                }
            }
            $lines[] = ['account' => Coa::id(Coa::SALARY_PAYABLE), 'credit' => (float) $slip->net, 'party' => $user, 'memo' => 'Net pay'];
        }

        return $this->ledger->sync('payslip', $id, 'payroll', $date, $slip->branch_id, $lines, 'Payroll ' . $slip->run->period . ' — ' . ($slip->user?->name ?? 'employee'));
    }

    public function payslipPayment(int $id): ?JournalEntry
    {
        $slip = Payslip::with('user:id,name')->find($id);
        if (!$slip) {
            return $this->clear('payslip_payment', $id);
        }

        $date = $slip->paid_on?->toDateString() ?? now()->toDateString();
        $lines = $slip->pay_status !== 'paid' || (float) $slip->net <= 0 || !$this->inBooks($date) ? [] : [
            ['account' => Coa::id(Coa::SALARY_PAYABLE), 'debit' => (float) $slip->net, 'party' => [JournalLine::PARTY_USER, (int) $slip->user_id]],
            ['account' => $this->methodAccount($slip->pay_method ?? 'bank_transfer'), 'credit' => (float) $slip->net, 'memo' => $slip->pay_reference ? "Ref {$slip->pay_reference}" : null],
        ];

        return $this->ledger->sync('payslip_payment', $id, 'salary_payment', $date, $slip->branch_id, $lines, 'Salary paid — ' . ($slip->user?->name ?? 'employee'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Cash goes to the cash box; every other method to the default bank account.
     */
    public function methodAccount(?string $method): int
    {
        return $method === 'cash' ? Coa::id(Coa::CASH) : BankAccount::defaultAccountId();
    }

    /**
     * Intra-state input GST, half CGST and half SGST.
     */
    protected function inputGst(float $gst): array
    {
        if ($gst <= 0) {
            return [];
        }
        $cgst = round($gst / 2, 2);

        return [
            ['account' => Coa::id(Coa::INPUT_CGST), 'debit' => $cgst],
            ['account' => Coa::id(Coa::INPUT_SGST), 'debit' => round($gst - $cgst, 2)],
        ];
    }

    /**
     * Sources dated before the books start are covered by the opening balances.
     */
    protected function inBooks(string $date): bool
    {
        $start = WorkshopSettings::get('accounts.books_start_date');

        return !$start || $date >= $start;
    }

    protected function clear(string $type, int $id): ?JournalEntry
    {
        $current = $this->ledger->current($type, $id);

        return $current ? $this->ledger->sync($type, $id, $current->voucher_type, $current->entry_date->toDateString(), $current->branch_id, []) : null;
    }
}
