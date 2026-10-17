<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Managers answer leave requests.
 */
class LeaveController extends ApiController
{
    public function __construct(protected LeaveService $leave) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->query('status', LeaveRequest::PENDING);

        $requests = LeaveRequest::with(['type', 'user:id,name', 'decidedBy:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(!$user->isOwner(), fn ($q) => $q->whereIn('branch_id', $user->getAccessibleBranchIds()))
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderByRaw("status = 'pending' DESC")
            ->latest('from_date')
            ->limit(200)
            ->get();

        return $this->success($requests->map(fn ($r) => $this->leave->present($r))->values());
    }

    public function decide(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate([
            'approve' => 'required|boolean',
            'note' => 'nullable|string|max:500',
        ]);
        $this->assertBranch($request, $leaveRequest);

        $r = $this->leave->decide($leaveRequest, (bool) $data['approve'], $data['note'] ?? null, $request->user());

        return $this->success($this->leave->present($r), $data['approve'] ? 'Leave approved' : 'Leave not approved');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->assertBranch($request, $leaveRequest);

        return $this->success($this->leave->present($this->leave->cancel($leaveRequest, $request->user())), 'Leave cancelled');
    }

    /**
     * An employee's balances (for the approver).
     */
    public function balances(Request $request, int $userId): JsonResponse
    {
        return $this->success($this->leave->balances($userId, (int) $request->query('year', now()->year)));
    }

    protected function assertBranch(Request $request, LeaveRequest $r): void
    {
        $user = $request->user();
        abort_unless($user->isOwner() || !$r->branch_id || in_array($r->branch_id, $user->getAccessibleBranchIds(), true), 404);
    }
}
