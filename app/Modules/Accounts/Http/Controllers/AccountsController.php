<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AccountingPeriod;
use App\Modules\Accounts\Models\BankAccount;
use App\Modules\Accounts\Services\AccountsReportService;
use App\Modules\Accounts\Services\LedgerException;
use App\Modules\Accounts\Support\ChartOfAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Masters › Accounts: chart of accounts, bank accounts, books start date, month locks.
 */
class AccountsController extends ApiController
{
    public function masters(): JsonResponse
    {
        return $this->success([
            'accounts' => Account::orderBy('code')->get(),
            'banks' => BankAccount::with('account:id,code,name')->orderBy('id')->get(),
            'periods' => AccountingPeriod::with('lockedBy:id,name')->whereNotNull('locked_at')->orderByDesc('period')->get()->map(fn ($p) => [
                'period' => $p->period, 'locked_by' => $p->lockedBy?->name, 'locked_at' => $p->locked_at?->toIso8601String(),
            ]),
            'books_start_date' => WorkshopSettings::get('accounts.books_start_date'),
            'types' => Account::TYPES,
            'groups' => Account::query()->whereNotNull('group')->distinct()->orderBy('group')->pluck('group'),
        ]);
    }

    public function saveAccount(Request $request, ?Account $account = null): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => 'required|string|max:120',
            'type' => ['required', Rule::in(Account::TYPES)],
            'group' => 'nullable|string|max:40',
            'is_expense_head' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($account?->is_system) {
            // A system head keeps its type and stays active
            unset($data['type'], $data['is_active']);
        }

        $account ??= new Account();
        $account->fill($data)->save();
        ChartOfAccounts::flush();

        return $this->success($account->fresh(), 'Account saved');
    }

    public function saveBank(Request $request, ?BankAccount $bank = null): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'account_number' => 'nullable|string|max:30',
            'ifsc' => 'nullable|string|max:15',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $bank = DB::transaction(function () use ($bank, $data) {
            if (!$bank) {
                // Each bank has its own ledger head under Cash & bank
                $next = (int) Account::where('code', 'like', '12%')->max('code') + 1;
                $head = Account::create([
                    'code' => (string) max(1201, $next),
                    'name' => 'Bank – ' . $data['name'],
                    'type' => 'asset',
                    'sub_type' => ChartOfAccounts::BANK,
                    'group' => 'Cash & bank',
                    'is_system' => false,
                    'is_active' => true,
                ]);
                $bank = new BankAccount(['account_id' => $head->id]);
            } else {
                $bank->account?->update(['name' => 'Bank – ' . $data['name']]);
            }
            if (!empty($data['is_default'])) {
                BankAccount::where('id', '!=', $bank->id ?? 0)->update(['is_default' => false]);
            }
            $bank->fill($data)->save();

            return $bank;
        });

        return $this->success($bank->fresh('account'), 'Bank account saved');
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['books_start_date' => 'nullable|date']);
        WorkshopSettings::set('accounts.books_start_date', $data['books_start_date'] ?? null);

        return $this->masters();
    }

    public function lockPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => 'required|string|size:7',
            'locked' => 'required|boolean',
        ]);
        if ($data['locked'] && $data['period'] >= now()->format('Y-m')) {
            throw new LedgerException('Only a month that is over can be locked.');
        }

        $period = AccountingPeriod::firstOrNew(['period' => $data['period']]);
        $period->fill($data['locked'] ? ['locked_by' => $request->user()->id, 'locked_at' => now()] : ['locked_by' => null, 'locked_at' => null])->save();

        return $this->masters();
    }

    /**
     * Post everything not yet on the books (e.g. after setting the books start date).
     */
    public function resync(): JsonResponse
    {
        Artisan::call('accounts:resync');

        return $this->success(['output' => Artisan::output()], 'Books brought up to date');
    }

    public function dashboard(Request $request, AccountsReportService $reports): JsonResponse
    {
        return $this->success($reports->dashboard($request->integer('branch_id') ?: null));
    }
}
