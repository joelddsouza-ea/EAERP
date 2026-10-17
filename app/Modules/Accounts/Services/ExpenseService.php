<?php

namespace App\Modules\Accounts\Services;

use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AccountingPeriod;
use App\Modules\Accounts\Models\Expense;
use App\Modules\Hr\Models\StaffCustodyEntry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Day-to-day spending. Paid from customer money an employee holds, it comes off what
 * they hold; on a GST bill its input GST is claimed.
 */
class ExpenseService
{
    public function __construct(protected FileStorageService $files) {}

    public function create(array $data, int $branchId, User $by, ?UploadedFile $bill = null): Expense
    {
        $data = $this->check($data);
        if (AccountingPeriod::isLocked($data['expense_date'])) {
            throw new LedgerException('That month is locked.');
        }

        $stored = $bill ? $this->files->uploadPrivateMedia($bill, 'expenses/' . substr($data['expense_date'], 0, 7)) : null;

        return DB::transaction(function () use ($data, $branchId, $by, $stored) {
            $expense = Expense::create([
                ...$data,
                'branch_id' => $branchId,
                'number' => Expense::generateNumber(),
                'bill_disk' => $stored['disk'] ?? null,
                'bill_path' => $stored['path'] ?? null,
                'status' => Expense::POSTED,
                'created_by' => $by->id,
            ]);
            $this->syncCustody($expense);

            return $expense;
        });
    }

    public function cancel(Expense $expense, string $reason, User $by): Expense
    {
        if ($expense->status !== Expense::POSTED) {
            throw new LedgerException('Already cancelled.');
        }

        DB::transaction(function () use ($expense, $reason) {
            $expense->update(['status' => Expense::CANCELLED, 'cancel_reason' => $reason]);
            $this->syncCustody($expense);
        });

        return $expense->fresh();
    }

    protected function check(array $data): array
    {
        $account = Account::where('is_active', true)->whereIn('type', ['expense', 'asset'])->find($data['account_id']);
        if (!$account) {
            throw new LedgerException('Choose what the money was spent on.', ['account_id' => ['Choose a head.']]);
        }

        $amount = round((float) $data['amount'], 2);
        $gstBill = (bool) ($data['gst_bill'] ?? false);
        $rate = $gstBill ? (float) ($data['gst_rate'] ?? 18) : 0.0;
        // Amount is GST-inclusive; the GST in it is worked out unless given
        $gst = $gstBill ? round((float) ($data['gst_amount'] ?? ($amount - $amount / (1 + $rate / 100))), 2) : 0.0;

        if ($data['paid_via'] === 'staff_custody') {
            if (empty($data['paid_by_user_id'])) {
                throw new LedgerException('Choose whose collected money paid for it.', ['paid_by_user_id' => ['Required.']]);
            }
            $held = StaffCustodyEntry::balanceFor((int) $data['paid_by_user_id']);
            if ($amount > $held + 0.001) {
                throw new LedgerException('They hold only Rs ' . number_format($held, 2) . ' of customer money.', ['amount' => ['More than they hold.']]);
            }
        }
        if ($data['paid_via'] === 'credit' && empty($data['supplier_id'])) {
            throw new LedgerException('Choose the supplier it is owed to.', ['supplier_id' => ['Required.']]);
        }

        return [
            'expense_date' => $data['expense_date'] ?? today()->toDateString(),
            'account_id' => $account->id,
            'paid_to' => trim((string) $data['paid_to']),
            'supplier_id' => $data['supplier_id'] ?? null,
            'amount' => $amount,
            'gst_bill' => $gstBill,
            'gst_rate' => $rate,
            'gst_amount' => min($gst, $amount),
            'supplier_gstin' => $data['supplier_gstin'] ?? null,
            'bill_number' => $data['bill_number'] ?? null,
            'paid_via' => $data['paid_via'],
            'bank_account_id' => $data['paid_via'] === 'bank' ? ($data['bank_account_id'] ?? null) : null,
            'paid_by_user_id' => $data['paid_via'] === 'staff_custody' ? (int) $data['paid_by_user_id'] : null,
            'job_card_id' => $data['job_card_id'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Spent from customer money an employee holds: their balance goes down (and back up
     * if the expense is cancelled).
     */
    protected function syncCustody(Expense $expense): void
    {
        $entry = StaffCustodyEntry::where('expense_id', $expense->id)->first();
        $wanted = $expense->status === Expense::POSTED && $expense->paid_via === 'staff_custody' && $expense->paid_by_user_id;

        if (!$wanted) {
            $entry?->delete();

            return;
        }

        StaffCustodyEntry::updateOrCreate(['expense_id' => $expense->id], [
            'user_id' => $expense->paid_by_user_id,
            'branch_id' => $expense->branch_id,
            'entry_date' => $expense->expense_date->toDateString(),
            'amount' => -(float) $expense->amount,
            'kind' => StaffCustodyEntry::SPENT,
            'note' => "Expense {$expense->number} — {$expense->paid_to}",
            'created_by' => $expense->created_by,
        ]);
    }

    public function present(Expense $e): array
    {
        $e->loadMissing(['account:id,name,code', 'supplier:id,name', 'paidBy:id,name', 'createdBy:id,name', 'bankAccount:id,name', 'jobCard:id,job_number']);

        return [
            'id' => $e->id,
            'number' => $e->number,
            'date' => $e->expense_date->toDateString(),
            'account_id' => $e->account_id,
            'account' => $e->account?->name,
            'paid_to' => $e->paid_to,
            'supplier' => $e->supplier?->name,
            'amount' => (float) $e->amount,
            'gst_bill' => $e->gst_bill,
            'gst_rate' => (float) $e->gst_rate,
            'gst_amount' => (float) $e->gst_amount,
            'bill_number' => $e->bill_number,
            'paid_via' => $e->paid_via,
            'bank' => $e->bankAccount?->name,
            'paid_by' => $e->paidBy?->name,
            'job_number' => $e->jobCard?->job_number,
            'job_card_id' => $e->job_card_id,
            'note' => $e->note,
            'status' => $e->status,
            'cancel_reason' => $e->cancel_reason,
            'has_bill' => (bool) $e->bill_path,
            'by' => $e->createdBy?->name,
        ];
    }
}
