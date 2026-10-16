<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Role;
use App\Core\Models\User;
use App\Modules\Workshop\Models\Bay;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardBayMove;
use App\Modules\Workshop\Services\JobCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Technicians and bay for a job: several technicians at once (one lead), and the
 * car moved between bays with the history kept. The candidates are included, so
 * advisors can assign without the user-admin permission.
 */
class JobCardAssignmentController extends ApiController
{
    private const TECHNICIAN_ROLES = [Role::TECHNICIAN, Role::BODY_SHOP_TECHNICIAN];

    public function __construct(protected JobCardService $jobCards) {}

    /**
     * Active technicians (optionally of one branch), for advisors and managers who
     * assign work but don't hold the user-admin permission.
     */
    public function technicians(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;

        $technicians = User::active()
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', self::TECHNICIAN_ROLES))
            ->when($branchId, fn ($q, $id) => $q->forBranch($id))
            ->with('roles:id,slug,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'roles' => $u->roles->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name])->values(),
            ]);

        return $this->success($technicians);
    }

    public function show(JobCard $jobCard): JsonResponse
    {
        return $this->success($this->present($jobCard));
    }

    public function addTechnician(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::in($this->candidateIds($jobCard))],
            'is_lead' => 'sometimes|boolean',
        ], ['user_id.in' => 'Choose a technician of this branch.']);

        $jobCard = $this->jobCards->addTechnician($jobCard, (int) $data['user_id'], (bool) ($data['is_lead'] ?? false));

        return $this->success($this->present($jobCard), 'Technician assigned');
    }

    public function removeTechnician(JobCard $jobCard, User $user): JsonResponse
    {
        $jobCard = $this->jobCards->removeTechnician($jobCard, $user->id);

        return $this->success($this->present($jobCard), 'Technician removed');
    }

    public function moveBay(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'bay_id' => ['required', Rule::exists('bays', 'id')->where('branch_id', $jobCard->branch_id)->where('is_active', true)],
            'notes' => 'nullable|string|max:255',
        ]);

        $jobCard = $this->jobCards->assignBay($jobCard, (int) $data['bay_id'], $data['notes'] ?? null);

        return $this->success($this->present($jobCard), $jobCard->wasChanged('bay_id') ? 'Bay assigned' : 'Bay saved');
    }

    private function candidates(JobCard $jobCard)
    {
        return User::active()
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', self::TECHNICIAN_ROLES))
            ->when($jobCard->branch_id, fn ($q, $branchId) => $q->forBranch($branchId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function candidateIds(JobCard $jobCard): array
    {
        return $this->candidates($jobCard)->pluck('id')->all();
    }

    private function present(JobCard $jobCard): array
    {
        $jobCard->load(['technicians:id,name', 'bay:id,name,code', 'bayMoves.fromBay:id,name', 'bayMoves.toBay:id,name', 'bayMoves.movedBy:id,name']);

        // Jobs assigned before co-technicians existed only have the lead column
        $technicians = $jobCard->technicians->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'is_lead' => (bool) $u->pivot->is_lead,
        ]);
        if ($jobCard->technician_id && !$technicians->contains('id', $jobCard->technician_id)) {
            $lead = User::find($jobCard->technician_id, ['id', 'name']);
            $technicians->prepend(['id' => $lead?->id, 'name' => $lead?->name, 'is_lead' => true]);
        }

        $busy = JobCard::whereNotIn('status', ['delivered', 'cancelled'])
            ->whereNotNull('bay_id')
            ->where('id', '!=', $jobCard->id)
            ->pluck('job_number', 'bay_id');

        return [
            'technicians' => $technicians->values(),
            'bay' => $jobCard->bay ? ['id' => $jobCard->bay->id, 'name' => $jobCard->bay->name] : null,
            'bay_moves' => $jobCard->bayMoves->map(fn (JobCardBayMove $m) => [
                'from' => $m->fromBay?->name,
                'to' => $m->toBay?->name,
                'notes' => $m->notes,
                'by' => $m->movedBy?->name,
                'at' => $m->moved_at?->toIso8601String(),
            ])->values(),
            'candidates' => [
                'technicians' => $this->candidates($jobCard),
                'bays' => Bay::where('branch_id', $jobCard->branch_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'type'])
                    ->map(fn (Bay $b) => [...$b->only(['id', 'name', 'code', 'type']), 'occupied_by' => $busy[$b->id] ?? null]),
            ],
        ];
    }
}
