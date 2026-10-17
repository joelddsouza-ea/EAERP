<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Accounts\Services\AccountsReportService;
use App\Modules\Accounts\Support\ChartOfAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountsReportController extends ApiController
{
    public function __construct(protected AccountsReportService $reports) {}

    protected function range(Request $request): array
    {
        $from = $request->query('from', today()->startOfMonth()->toDateString());
        $to = $request->query('to', today()->toDateString());

        return [$from, $to, $request->integer('branch_id') ?: null];
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [, $to, $branch] = $this->range($request);

        return $this->success($this->reports->trialBalance($to, $branch));
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        return $this->success($this->reports->profitAndLoss(...$this->range($request)));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        [, $to, $branch] = $this->range($request);

        return $this->success($this->reports->balanceSheet($to, $branch));
    }

    public function ledger(Request $request): JsonResponse
    {
        $request->validate(['account_id' => 'required|integer|exists:accounts,id']);
        [$from, $to, $branch] = $this->range($request);
        $party = $request->query('party_type') ? [$request->query('party_type'), $request->integer('party_id')] : null;

        return $this->success($this->reports->ledger($request->integer('account_id'), $from, $to, $branch, $party));
    }

    /**
     * Cash book (cash in hand) or bank book (a bank's head).
     */
    public function cashBook(Request $request): JsonResponse
    {
        [$from, $to, $branch] = $this->range($request);
        $accountId = $request->integer('account_id') ?: ChartOfAccounts::id(ChartOfAccounts::CASH);

        return $this->success($this->reports->ledger($accountId, $from, $to, $branch));
    }

    public function dayBook(Request $request): JsonResponse
    {
        [$from, $to, $branch] = $this->range($request);

        return $this->success($this->reports->dayBook($from, $to, $branch, $request->query('voucher_type')));
    }

    /**
     * Customers who owe (debtors), suppliers owed (creditors), staff holding money / advances.
     */
    public function parties(Request $request): JsonResponse
    {
        $request->validate(['of' => 'required|in:debtors,creditors,staff_custody,salary_advances,salary_payable']);
        [, $to, $branch] = $this->range($request);
        $subType = [
            'debtors' => ChartOfAccounts::DEBTORS,
            'creditors' => ChartOfAccounts::CREDITORS,
            'staff_custody' => ChartOfAccounts::STAFF_CUSTODY,
            'salary_advances' => ChartOfAccounts::SALARY_ADVANCES,
            'salary_payable' => ChartOfAccounts::SALARY_PAYABLE,
        ][$request->query('of')];

        $rows = $this->reports->partyBalances($subType, $to, $branch);

        return $this->success([
            'account_id' => ChartOfAccounts::id($subType),
            'rows' => $rows,
            'total' => round($rows->sum('balance'), 2),
        ]);
    }

    public function gst(Request $request): JsonResponse
    {
        return $this->success($this->reports->gstSummary(...$this->range($request)));
    }

    /**
     * Every voucher line as CSV (opens in Excel; columns map onto a Tally voucher import).
     */
    public function exportVouchers(Request $request): StreamedResponse
    {
        [$from, $to, $branch] = $this->range($request);
        $rows = $this->reports->voucherRows($from, $to, $branch);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "vouchers-{$from}-to-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
