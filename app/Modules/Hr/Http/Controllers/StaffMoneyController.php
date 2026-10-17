<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Hr\Services\StaffMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Salary advances, and customer money held by staff.
 */
class StaffMoneyController extends ApiController
{
    public function __construct(protected StaffMoneyService $money) {}

    public function advances(Request $request): JsonResponse
    {
        $status = $request->query('status', SalaryAdvance::OPEN);

        return $this->success(SalaryAdvance::with(['user:id,name', 'createdBy:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->latest('given_on')
            ->limit(200)
            ->get()
            ->map(fn ($a) => $this->money->presentAdvance($a))
            ->values());
    }

    public function giveAdvance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:1|max:10000000',
            'given_on' => 'nullable|date|before_or_equal:today',
            'method' => 'required|in:cash,bank_transfer,upi,cheque',
            'reference_number' => 'nullable|string|max:100',
            'recover_per_month' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $advance = $this->money->giveAdvance(User::findOrFail($data['user_id']), $data, $request->user());

        return $this->created($this->money->presentAdvance($advance), 'Advance recorded');
    }

    public function updateAdvance(Request $request, SalaryAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'recover_per_month' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        return $this->success($this->money->presentAdvance($this->money->updateAdvance($advance, $data)), 'Advance updated');
    }

    public function cancelAdvance(Request $request, SalaryAdvance $advance): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);

        return $this->success($this->money->presentAdvance($this->money->cancelAdvance($advance, $data['reason'])), 'Advance cancelled');
    }

    public function custody(Request $request): JsonResponse
    {
        $userId = $request->integer('user_id') ?: null;

        return $this->success([
            'summary' => $this->money->custodySummary($request->integer('branch_id') ?: null),
            'entries' => StaffCustodyEntry::with(['user:id,name', 'createdBy:id,name'])
                ->when($userId, fn ($q) => $q->where('user_id', $userId))
                ->latest('entry_date')
                ->latest('id')
                ->limit(200)
                ->get()
                ->map(fn ($e) => $this->money->presentCustody($e))
                ->values(),
        ]);
    }

    public function handOver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:1',
            'method' => 'required|in:cash,bank',
            'entry_date' => 'nullable|date|before_or_equal:today',
            'reference_number' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        $entry = $this->money->handOver(User::findOrFail($data['user_id']), $data, $request->user());

        return $this->created($this->money->presentCustody($entry), 'Hand-over recorded');
    }
}
