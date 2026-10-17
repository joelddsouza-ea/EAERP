<?php

namespace App\Modules\Hr\Services;

use App\Core\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\PaymentPart;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Money between the workshop and its staff:
 *  - customer money an employee collected (their UPI / cash in hand) is held by them until
 *    handed over, spent on the workshop (an expense), or recovered from their salary;
 *  - salary advances, recovered from payslips.
 */
class StaffMoneyService
{
    // --------------------------------------------------------- custody

    /**
     * A receipt part the customer paid to an employee: they now hold it.
     */
    public function collected(Payment $payment, PaymentPart $part): StaffCustodyEntry
    {
        return StaffCustodyEntry::create([
            'user_id' => $part->collected_by,
            'branch_id' => $payment->branch_id,
            'entry_date' => $payment->payment_date?->toDateString() ?? today()->toDateString(),
            'amount' => (float) $part->amount,
            'kind' => StaffCustodyEntry::COLLECTED,
            'payment_part_id' => $part->id,
            'reference_number' => $part->reference_number,
            'note' => "Receipt {$payment->payment_number}",
            'created_by' => $payment->received_by,
        ]);
    }

    /**
     * The employee hands collected money over to the cash box or the bank.
     */
    public function handOver(User $employee, array $data, User $by): StaffCustodyEntry
    {
        $amount = round((float) $data['amount'], 2);
        $held = StaffCustodyEntry::balanceFor($employee->id);

        if ($amount <= 0) {
            throw new HrException('Enter the amount handed over.', ['amount' => ['Required.']]);
        }
        if ($amount > $held + 0.001) {
            throw new HrException("{$employee->name} holds only Rs " . number_format($held, 2) . '.', ['amount' => ['More than they hold.']]);
        }

        return StaffCustodyEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $data['branch_id'] ?? app(AttendanceService::class)->homeBranchId($employee),
            'entry_date' => $data['entry_date'] ?? today()->toDateString(),
            'amount' => -$amount,
            'kind' => StaffCustodyEntry::HANDED_OVER,
            'method' => ($data['method'] ?? 'cash') === 'cash' ? 'cash' : 'bank_transfer',
            'reference_number' => $data['reference_number'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $by->id,
        ]);
    }

    /**
     * Each employee's held money (only those with a movement).
     *
     * @return Collection<int, array{user_id: int, name: string, collected: float, handed_over: float, spent: float, recovered: float, balance: float}>
     */
    public function custodySummary(?int $branchId = null): Collection
    {
        $rows = StaffCustodyEntry::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('user_id, kind, SUM(amount) AS total')
            ->groupBy('user_id', 'kind')
            ->get()
            ->groupBy('user_id');
        $names = User::whereIn('id', $rows->keys())->pluck('name', 'id');

        return $rows->map(function ($kinds, $userId) use ($names) {
            $sum = fn (string $kind) => round(abs((float) ($kinds->firstWhere('kind', $kind)?->total ?? 0)), 2);

            return [
                'user_id' => (int) $userId,
                'name' => $names[$userId] ?? '—',
                'collected' => $sum(StaffCustodyEntry::COLLECTED),
                'handed_over' => $sum(StaffCustodyEntry::HANDED_OVER),
                'spent' => $sum(StaffCustodyEntry::SPENT),
                'recovered' => $sum(StaffCustodyEntry::SALARY_RECOVERY),
                'balance' => round((float) $kinds->sum('total'), 2),
            ];
        })->sortByDesc('balance')->values();
    }

    public function presentCustody(StaffCustodyEntry $e): array
    {
        $e->loadMissing(['user:id,name', 'createdBy:id,name']);

        return [
            'id' => $e->id,
            'user_id' => $e->user_id,
            'employee' => $e->user?->name,
            'date' => $e->entry_date->toDateString(),
            'kind' => $e->kind,
            'amount' => $e->amount,
            'method' => $e->method,
            'reference_number' => $e->reference_number,
            'note' => $e->note,
            'by' => $e->createdBy?->name,
        ];
    }

    // -------------------------------------------------------- advances

    public function giveAdvance(User $employee, array $data, User $by): SalaryAdvance
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new HrException('Enter the advance amount.', ['amount' => ['Required.']]);
        }

        return SalaryAdvance::create([
            'user_id' => $employee->id,
            'branch_id' => app(AttendanceService::class)->homeBranchId($employee),
            'amount' => $amount,
            'given_on' => $data['given_on'] ?? today()->toDateString(),
            'method' => $data['method'] ?? 'cash',
            'reference_number' => $data['reference_number'] ?? null,
            'recover_per_month' => round((float) ($data['recover_per_month'] ?? 0), 2),
            'status' => SalaryAdvance::OPEN,
            'note' => $data['note'] ?? null,
            'created_by' => $by->id,
        ]);
    }

    public function updateAdvance(SalaryAdvance $advance, array $data): SalaryAdvance
    {
        if ($advance->status !== SalaryAdvance::OPEN) {
            throw new HrException('Only an open advance can be changed.');
        }
        $advance->update(['recover_per_month' => round((float) $data['recover_per_month'], 2), 'note' => $data['note'] ?? $advance->note]);

        return $advance->fresh();
    }

    public function cancelAdvance(SalaryAdvance $advance, string $reason): SalaryAdvance
    {
        if ($advance->recovered > 0) {
            throw new HrException('Part of this advance is already recovered; it cannot be cancelled.');
        }
        if ($advance->status === SalaryAdvance::CANCELLED) {
            throw new HrException('Already cancelled.');
        }

        DB::transaction(fn () => $advance->update(['status' => SalaryAdvance::CANCELLED, 'note' => trim(($advance->note ? $advance->note . ' | ' : '') . 'Cancelled: ' . $reason)]));

        return $advance->fresh();
    }

    public function presentAdvance(SalaryAdvance $a): array
    {
        $a->loadMissing(['user:id,name', 'createdBy:id,name']);

        return [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'employee' => $a->user?->name,
            'amount' => $a->amount,
            'given_on' => $a->given_on->toDateString(),
            'method' => $a->method,
            'reference_number' => $a->reference_number,
            'recover_per_month' => $a->recover_per_month,
            'recovered' => $a->recovered,
            'balance' => $a->balance(),
            'status' => $a->status,
            'note' => $a->note,
            'by' => $a->createdBy?->name,
        ];
    }
}
