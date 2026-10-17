<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\BankAccount;
use App\Modules\Accounts\Models\Expense;
use App\Modules\Accounts\Services\ExpenseService;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Workshop\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExpenseController extends ApiController
{
    public function __construct(protected ExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->startOfMonth()->toDateString());
        $to = $request->query('to', today()->toDateString());

        $rows = Expense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->integer('account_id'), fn ($q, $id) => $q->where('account_id', $id))
            ->when($request->query('paid_via'), fn ($q, $v) => $q->where('paid_via', $v))
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('paid_to', 'like', "%{$t}%")->orWhere('note', 'like', "%{$t}%")->orWhere('number', 'like', "%{$t}%")))
            ->latest('expense_date')
            ->latest('id')
            ->limit(500)
            ->get();
        $posted = $rows->where('status', Expense::POSTED);

        return $this->success([
            'from' => $from,
            'to' => $to,
            'rows' => $rows->map(fn ($e) => $this->expenses->present($e))->values(),
            'total' => round((float) $posted->sum('amount'), 2),
            'by_head' => $posted->groupBy('account_id')->map(fn ($g) => ['account' => $g->first()->account?->name, 'total' => round((float) $g->sum('amount'), 2)])->sortByDesc('total')->values(),
        ]);
    }

    /**
     * Choices for the expense form.
     */
    public function options(): JsonResponse
    {
        return $this->success([
            'heads' => Account::where('is_active', true)->where(fn ($q) => $q->where('is_expense_head', true)->orWhere(fn ($q) => $q->where('type', 'expense')->whereNull('sub_type')))
                ->orderBy('name')->get(['id', 'code', 'name', 'group']),
            'banks' => BankAccount::where('is_active', true)->orderByDesc('is_default')->get(['id', 'name', 'is_default']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'staff_holding' => StaffCustodyEntry::query()->groupBy('user_id')->selectRaw('user_id, SUM(amount) AS held')->havingRaw('SUM(amount) > 0')->get()
                ->map(fn ($r) => ['user_id' => (int) $r->user_id, 'name' => \App\Core\Models\User::whereKey($r->user_id)->value('name'), 'held' => round((float) $r->held, 2)])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expense_date' => 'required|date|before_or_equal:today',
            'account_id' => 'required|integer|exists:accounts,id',
            'paid_to' => 'required|string|max:150',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'gst_bill' => 'boolean',
            'gst_rate' => 'nullable|numeric|in:0,5,12,18,28',
            'gst_amount' => 'nullable|numeric|min:0',
            'supplier_gstin' => 'nullable|string|max:20',
            'bill_number' => 'nullable|string|max:60',
            'paid_via' => ['required', Rule::in(Expense::PAID_VIA)],
            'bank_account_id' => 'nullable|integer|exists:bank_accounts,id',
            'paid_by_user_id' => 'nullable|integer|exists:users,id',
            'job_card_id' => 'nullable|integer|exists:job_cards,id',
            'note' => 'nullable|string|max:500',
            'bill' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf|max:' . (int) config('workshop.job_sheets.max_photo_kb'),
        ]);

        $branchId = $request->integer('branch_id') ?: app('branch.context')->getCurrentBranchId();
        $expense = $this->expenses->create($data, $branchId, $request->user(), $request->file('bill'));

        return $this->created($this->expenses->present($expense), "Expense {$expense->number} saved");
    }

    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);

        return $this->success($this->expenses->present($this->expenses->cancel($expense, $data['reason'], $request->user())), 'Expense cancelled');
    }

    public function billUrl(Expense $expense): JsonResponse
    {
        abort_unless($expense->bill_path, 404);

        return $this->success(['url' => URL::temporarySignedRoute('accounts.expense-bill', now()->addMinutes(15), ['expense' => $expense->id])]);
    }

    public function bill(Expense $expense): BinaryFileResponse
    {
        $disk = Storage::disk($expense->bill_disk ?: 'job_media');
        abort_unless($expense->bill_path && $disk->exists($expense->bill_path), 404);

        return response()->file($disk->path($expense->bill_path), ['Cache-Control' => 'private, max-age=600']);
    }
}
