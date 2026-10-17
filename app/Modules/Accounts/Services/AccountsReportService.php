<?php

namespace App\Modules\Accounts\Services;

use App\Core\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Expense;
use App\Modules\Accounts\Models\JournalEntry;
use App\Modules\Accounts\Models\JournalLine;
use App\Modules\Accounts\Support\ChartOfAccounts as Coa;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\GoodsReceipt;
use App\Modules\Workshop\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Books and statements: trial balance, P&L, balance sheet, ledgers, day book, cash &
 * bank book, party balances and the GST summary for the CA.
 */
class AccountsReportService
{
    public function __construct(protected LedgerService $ledger) {}

    /**
     * Debit / credit totals per account in a period (or up to a date).
     *
     * @return Collection<int, object{account_id: int, debit: float, credit: float}>
     */
    protected function sums(?string $from, ?string $to, ?int $branchId): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.entry_date', '<=', $to))
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get()
            ->keyBy('account_id');
    }

    public function trialBalance(string $to, ?int $branchId = null): array
    {
        $sums = $this->sums(null, $to, $branchId);
        $rows = Account::orderBy('code')->get()->map(function (Account $a) use ($sums) {
            $s = $sums[$a->id] ?? null;
            $net = round((float) ($s->debit ?? 0) - (float) ($s->credit ?? 0), 2);

            return [
                'account_id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'group' => $a->group,
                'debit' => $net > 0 ? $net : 0.0,
                'credit' => $net < 0 ? -$net : 0.0,
            ];
        })->filter(fn ($r) => $r['debit'] > 0 || $r['credit'] > 0)->values();

        return [
            'to' => $to,
            'rows' => $rows,
            'total_debit' => round($rows->sum('debit'), 2),
            'total_credit' => round($rows->sum('credit'), 2),
        ];
    }

    public function profitAndLoss(string $from, string $to, ?int $branchId = null): array
    {
        $sums = $this->sums($from, $to, $branchId);
        $accounts = Account::whereIn('type', ['income', 'expense'])->orderBy('code')->get();

        $section = function (string $type) use ($accounts, $sums) {
            return $accounts->where('type', $type)->map(function (Account $a) use ($sums, $type) {
                $s = $sums[$a->id] ?? null;
                $net = round((float) ($s->debit ?? 0) - (float) ($s->credit ?? 0), 2);

                return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'amount' => $type === 'income' ? -$net : $net];
            })->filter(fn ($r) => $r['amount'] != 0.0)->values();
        };

        $income = $section('income');
        $expenses = $section('expense');
        $direct = $expenses->where('group', 'Direct costs');

        $totalIncome = round($income->sum('amount'), 2);
        $totalExpense = round($expenses->sum('amount'), 2);

        return [
            'from' => $from,
            'to' => $to,
            'income' => $this->grouped($income),
            'expenses' => $this->grouped($expenses),
            'total_income' => $totalIncome,
            'total_direct_costs' => round($direct->sum('amount'), 2),
            'gross_profit' => round($income->where('group', 'Sales')->sum('amount') - $direct->sum('amount'), 2),
            'total_expenses' => $totalExpense,
            'net_profit' => round($totalIncome - $totalExpense, 2),
        ];
    }

    public function balanceSheet(string $asOf, ?int $branchId = null): array
    {
        $sums = $this->sums(null, $asOf, $branchId);
        $accounts = Account::whereIn('type', ['asset', 'liability', 'equity'])->orderBy('code')->get();

        $section = function (string $type) use ($accounts, $sums) {
            return $accounts->where('type', $type)->map(function (Account $a) use ($sums, $type) {
                $s = $sums[$a->id] ?? null;
                $net = round((float) ($s->debit ?? 0) - (float) ($s->credit ?? 0), 2);

                return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'amount' => $type === 'asset' ? $net : -$net];
            })->filter(fn ($r) => $r['amount'] != 0.0)->values();
        };

        // Profit to date (not yet closed into capital)
        $pl = Account::whereIn('type', ['income', 'expense'])->pluck('id');
        $profit = -$this->ledger->balance($pl->all(), $asOf, null, $branchId);

        $assets = $section('asset');
        $liabilities = $section('liability');
        $equity = $section('equity');
        $equity->push(['account_id' => null, 'code' => '', 'name' => 'Profit & loss (to date)', 'group' => 'Capital', 'amount' => round($profit, 2)]);

        return [
            'as_of' => $asOf,
            'assets' => $this->grouped($assets),
            'liabilities' => $this->grouped($liabilities),
            'equity' => $this->grouped($equity),
            'total_assets' => round($assets->sum('amount'), 2),
            'total_liabilities_equity' => round($liabilities->sum('amount') + $equity->sum('amount'), 2),
        ];
    }

    /**
     * One account's movements with a running balance (optionally one party's).
     */
    public function ledger(int $accountId, string $from, string $to, ?int $branchId = null, ?array $party = null): array
    {
        $account = Account::findOrFail($accountId);
        $sign = $account->isDebitNature() ? 1 : -1;
        $opening = $this->ledger->balance($accountId, date('Y-m-d', strtotime($from . ' -1 day')), null, $branchId, $party);

        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->when($party, fn ($q) => $q->where('journal_lines.party_type', $party[0])->where('journal_lines.party_id', $party[1]))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->get(['journal_lines.*', 'journal_entries.entry_date', 'journal_entries.number', 'journal_entries.voucher_type', 'journal_entries.narration', 'journal_entries.id as entry_id']);

        $parties = $this->partyNames($lines);
        $running = $opening;
        $rows = $lines->map(function ($l) use (&$running, $parties) {
            $running = round($running + (float) $l->debit - (float) $l->credit, 2);

            return [
                'entry_id' => $l->entry_id,
                'date' => substr((string) $l->entry_date, 0, 10),
                'number' => $l->number,
                'voucher_type' => JournalEntry::VOUCHER_TYPES[$l->voucher_type] ?? $l->voucher_type,
                'narration' => $l->narration,
                'party' => $l->party_type ? ($parties[$l->party_type . ':' . $l->party_id] ?? null) : null,
                'memo' => $l->memo,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'balance' => $running,
            ];
        });

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type],
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'rows' => $rows->values(),
            'total_debit' => round($rows->sum('debit'), 2),
            'total_credit' => round($rows->sum('credit'), 2),
            'closing' => $running,
            // How the balance reads: debit-nature accounts show + as Dr
            'sign' => $sign,
        ];
    }

    public function dayBook(string $from, string $to, ?int $branchId = null, ?string $voucherType = null): array
    {
        $entries = JournalEntry::with(['lines.account:id,code,name', 'branch:id,name', 'createdBy:id,name'])
            ->whereBetween('entry_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($voucherType, fn ($q) => $q->where('voucher_type', $voucherType))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'entries' => $entries->map(fn (JournalEntry $e) => $this->presentEntry($e))->values(),
        ];
    }

    public function presentEntry(JournalEntry $e): array
    {
        $e->loadMissing(['lines.account:id,code,name', 'branch:id,name', 'createdBy:id,name']);
        $parties = $this->partyNames($e->lines);

        return [
            'id' => $e->id,
            'number' => $e->number,
            'date' => $e->entry_date->toDateString(),
            'voucher_type' => $e->voucher_type,
            'voucher_label' => JournalEntry::VOUCHER_TYPES[$e->voucher_type] ?? $e->voucher_type,
            'narration' => $e->narration,
            'status' => $e->status,
            'branch' => $e->branch?->name,
            'amount' => (float) $e->amount,
            'source_type' => $e->source_type,
            'source_id' => $e->source_id,
            'by' => $e->createdBy?->name,
            'lines' => $e->lines->map(fn (JournalLine $l) => [
                'account' => $l->account?->name,
                'code' => $l->account?->code,
                'party' => $l->party_type ? ($parties[$l->party_type . ':' . $l->party_id] ?? null) : null,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'memo' => $l->memo,
            ])->values(),
        ];
    }

    /**
     * Balances per customer / supplier / employee on an account.
     */
    public function partyBalances(string $subType, ?string $asOf = null, ?int $branchId = null): Collection
    {
        $accountId = Coa::id($subType);
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereNotNull('journal_lines.party_type')
            ->when($asOf, fn ($q) => $q->where('journal_entries.entry_date', '<=', $asOf))
            ->when($branchId, fn ($q) => $q->where('journal_entries.branch_id', $branchId))
            ->groupBy('journal_lines.party_type', 'journal_lines.party_id')
            ->selectRaw('journal_lines.party_type, journal_lines.party_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get();
        $names = $this->partyNames($rows);
        $debitNature = Account::find($accountId)->isDebitNature();

        return $rows->map(function ($r) use ($names, $debitNature) {
            $net = round((float) $r->debit - (float) $r->credit, 2);

            return [
                'party_type' => $r->party_type,
                'party_id' => (int) $r->party_id,
                'name' => $names[$r->party_type . ':' . $r->party_id] ?? '—',
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
                'balance' => $debitNature ? $net : -$net,
            ];
        })->filter(fn ($r) => abs($r['balance']) >= 0.01)->sortByDesc('balance')->values();
    }

    /**
     * GST for the period from the bills themselves: output by rate and HSN/SAC (B2B
     * invoices listed), input from GST purchase bills and expenses, and the net payable.
     */
    public function gstSummary(string $from, string $to, ?int $branchId = null): array
    {
        $invoices = Invoice::withoutGlobalScopes()
            ->with(['items', 'customer:id,name'])
            ->whereBetween('invoice_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', Invoice::KIND_TAX)->whereIn('status', [Invoice::ISSUED, Invoice::PARTIAL, Invoice::PAID]))
                ->orWhere(fn ($q) => $q->where('kind', Invoice::KIND_PROFORMA)->where('status', Invoice::CLOSED)))
            ->get();

        $byRate = [];
        $byHsn = [];
        foreach ($invoices as $inv) {
            foreach ($inv->items as $item) {
                $rate = (string) (float) $item->tax_rate;
                $byRate[$rate] ??= ['rate' => (float) $rate, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0];
                $byRate[$rate]['taxable'] += (float) $item->taxable_amount;
                $byRate[$rate]['cgst'] += (float) $item->cgst;
                $byRate[$rate]['sgst'] += (float) $item->sgst;
                $byRate[$rate]['igst'] += (float) $item->igst;

                $hsn = $item->hsn_code ?: '—';
                $key = $hsn . '|' . $rate;
                $byHsn[$key] ??= ['hsn' => $hsn, 'rate' => (float) $rate, 'quantity' => 0.0, 'taxable' => 0.0, 'tax' => 0.0];
                $byHsn[$key]['quantity'] += (float) $item->quantity;
                $byHsn[$key]['taxable'] += (float) $item->taxable_amount;
                $byHsn[$key]['tax'] += (float) $item->cgst + (float) $item->sgst + (float) $item->igst;
            }
        }
        $round = fn (array $rows) => collect($rows)->map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r))->values();

        $b2b = $invoices->filter(fn ($i) => $i->customer_gstin)->map(fn (Invoice $i) => [
            'number' => $i->invoice_number,
            'date' => $i->invoice_date->toDateString(),
            'customer' => $i->customer?->name,
            'gstin' => $i->customer_gstin,
            'taxable' => (float) $i->taxable_amount,
            'tax' => (float) $i->total_tax,
            'total' => (float) $i->total_amount,
        ])->values();

        $output = [
            'taxable' => round((float) $invoices->sum('taxable_amount'), 2),
            'cgst' => round((float) $invoices->sum('cgst'), 2),
            'sgst' => round((float) $invoices->sum('sgst'), 2),
            'igst' => round((float) $invoices->sum('igst'), 2),
        ];

        $grns = GoodsReceipt::with(['lines', 'supplier:id,name'])
            ->where('status', GoodsReceipt::POSTED)
            ->where('bill_type', GoodsReceipt::BILL_GST)
            ->whereBetween('bill_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();
        $grnInput = round((float) $grns->sum(function (GoodsReceipt $g) {
            return $g->lines->sum(function ($line) {
                $share = (float) $line->total > 0 ? \App\Modules\Workshop\Services\GoodsReceiptService::lineCost($line) / (float) $line->total : 0;

                return (float) $line->gst_amount * $share;
            });
        }), 2);
        $expenseInput = round((float) Expense::where('status', Expense::POSTED)->where('gst_bill', true)
            ->whereBetween('expense_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('gst_amount'), 2);

        $outputTax = round($output['cgst'] + $output['sgst'] + $output['igst'], 2);
        $inputTax = round($grnInput + $expenseInput, 2);

        return [
            'from' => $from,
            'to' => $to,
            'output' => [...$output, 'tax' => $outputTax, 'invoices' => $invoices->count()],
            'by_rate' => $round($byRate),
            'by_hsn' => $round($byHsn),
            'b2b' => $b2b,
            'b2c_taxable' => round((float) $invoices->whereNull('customer_gstin')->sum('taxable_amount'), 2),
            'input' => [
                'purchases' => $grnInput,
                'expenses' => $expenseInput,
                'tax' => $inputTax,
                'purchase_bills' => $grns->count(),
            ],
            'net_payable' => round($outputTax - $inputTax, 2),
        ];
    }

    /**
     * Owner dashboard tiles.
     */
    public function dashboard(?int $branchId = null): array
    {
        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $bankIds = Account::where('sub_type', Coa::BANK)->pluck('id')->all();
        $pl = $this->profitAndLoss($monthStart, $today, $branchId);

        return [
            'cash_in_hand' => $this->ledger->balance(Coa::id(Coa::CASH), $today, null, $branchId),
            'bank' => $bankIds ? $this->ledger->balance($bankIds, $today, null, $branchId) : 0.0,
            'receivable' => $this->ledger->balance(Coa::id(Coa::DEBTORS), $today, null, $branchId),
            'payable' => -$this->ledger->balance(Coa::id(Coa::CREDITORS), $today, null, $branchId),
            'staff_held' => $this->ledger->balance(Coa::id(Coa::STAFF_CUSTODY), $today, null, $branchId),
            'expenses_today' => round((float) Expense::where('status', Expense::POSTED)->where('expense_date', $today)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('amount'), 2),
            'month_income' => $pl['total_income'],
            'month_expenses' => $pl['total_expenses'],
            'month_profit' => $pl['net_profit'],
        ];
    }

    /**
     * Vouchers as flat rows (date, number, type, account, party, debit, credit, narration)
     * for import into Tally or a spreadsheet.
     *
     * @return array<int, array<int, mixed>>
     */
    public function voucherRows(string $from, string $to, ?int $branchId = null): array
    {
        $rows = [['Date', 'Voucher no', 'Voucher type', 'Account code', 'Account', 'Party', 'Debit', 'Credit', 'Narration', 'Branch']];
        foreach ($this->dayBook($from, $to, $branchId)['entries'] as $e) {
            foreach ($e['lines'] as $l) {
                $rows[] = [$e['date'], $e['number'], $e['voucher_label'], $l['code'], $l['account'], $l['party'], $l['debit'] ?: '', $l['credit'] ?: '', $e['narration'], $e['branch']];
            }
        }

        return $rows;
    }

    // ---------------------------------------------------------------- helpers

    protected function grouped(Collection $rows): array
    {
        return $rows->groupBy(fn ($r) => $r['group'] ?: 'Other')
            ->map(fn ($items, $group) => ['group' => $group, 'total' => round($items->sum('amount'), 2), 'items' => $items->values()])
            ->values()
            ->all();
    }

    /**
     * "type:id" => name for the parties on some lines.
     *
     * @return array<string, string>
     */
    protected function partyNames(iterable $lines): array
    {
        $ids = ['customer' => [], 'supplier' => [], 'user' => []];
        foreach ($lines as $l) {
            if ($l->party_type && isset($ids[$l->party_type])) {
                $ids[$l->party_type][] = (int) $l->party_id;
            }
        }

        $names = [];
        foreach (Customer::withTrashed()->whereIn('id', array_unique($ids['customer']))->pluck('name', 'id') as $id => $n) {
            $names["customer:{$id}"] = $n;
        }
        foreach (Supplier::withTrashed()->whereIn('id', array_unique($ids['supplier']))->pluck('name', 'id') as $id => $n) {
            $names["supplier:{$id}"] = $n;
        }
        foreach (User::withTrashed()->whereIn('id', array_unique($ids['user']))->pluck('name', 'id') as $id => $n) {
            $names["user:{$id}"] = $n;
        }

        return $names;
    }
}
