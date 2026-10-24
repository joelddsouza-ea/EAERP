<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Role;
use App\Core\Models\User;
use App\Core\Rules\IndianMobile;
use App\Modules\UserAdmin\Services\UserService;
use App\Modules\Workshop\Models\ServicePartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Masters › Outside Service Partners: washing and wheel alignment & balancing partners
 * (several per kind) and their logins.
 */
class ServicePartnerController extends ApiController
{
    public function index(): JsonResponse
    {
        $partners = ServicePartner::with(['user:id,name,phone,is_active', 'branch:id,name'])->orderBy('kind')->orderBy('name')->get();

        return $this->success([
            'partners' => $partners->map(fn (ServicePartner $p) => $this->present($p))->values(),
            'kinds' => ServicePartner::KINDS,
        ]);
    }

    /**
     * Active partners of a kind for this branch (the job card form's choice).
     */
    public function options(Request $request): JsonResponse
    {
        $kind = $request->validate(['kind' => 'required|string|in:' . implode(',', array_keys(ServicePartner::KINDS))])['kind'];

        return $this->success(ServicePartner::where('kind', $kind)->where('is_active', true)
            ->forBranch(app('branch.context')->getCurrentBranchId())
            ->orderBy('name')->get(['id', 'name']));
    }

    public function store(Request $request): JsonResponse
    {
        $partner = ServicePartner::create($this->input($request));

        return $this->created($this->present($partner->load(['user', 'branch'])), 'Partner added');
    }

    public function update(Request $request, ServicePartner $partner): JsonResponse
    {
        $partner->update($this->input($request));
        // Switched off: their login stops working too
        if (!$partner->is_active && $partner->user) {
            $partner->user->update(['is_active' => false]);
            $partner->user->tokens()->delete();
        }

        return $this->success($this->present($partner->fresh(['user', 'branch'])), 'Saved');
    }

    /**
     * A login for the partner: they sign in with their mobile number and this password.
     */
    public function createLogin(Request $request, ServicePartner $partner, UserService $users): JsonResponse
    {
        if ($partner->user_id) {
            return $this->error('This partner already has a login.', 422);
        }
        $request->merge(['phone' => IndianMobile::clean($request->input('phone'))]);
        $data = $request->validate([
            'phone' => ['required', 'string', new IndianMobile(), Rule::unique('users', 'phone')],
            'password' => 'required|string|min:8',
        ]);

        $branchId = $partner->branch_id ?? app('branch.context')->getCurrentBranchId() ?? $request->user()->branches()->value('branches.id');
        $user = $users->createUser([
            'name' => $partner->name,
            'phone' => $data['phone'],
            'password' => $data['password'],
            'roles' => [Role::SERVICE_PARTNER],
            'branches' => [$branchId],
            'primary_branch_id' => $branchId,
        ]);
        $partner->update(['user_id' => $user->id, 'phone' => $partner->phone ?: $data['phone']]);

        return $this->success($this->present($partner->fresh(['user', 'branch'])), 'Login created: they sign in with ' . $data['phone']);
    }

    private function input(Request $request): array
    {
        $request->merge(['phone' => IndianMobile::clean($request->input('phone'))]);

        return $request->validate([
            'name' => 'required|string|max:120',
            'phone' => ['nullable', 'string', new IndianMobile()],
            'kind' => 'required|string|in:' . implode(',', array_keys(ServicePartner::KINDS)),
            'branch_id' => 'nullable|integer|exists:branches,id',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:255',
        ]);
    }

    private function present(ServicePartner $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'phone' => $p->phone,
            'kind' => $p->kind,
            'kind_label' => $p->kindLabel(),
            'branch_id' => $p->branch_id,
            'branch' => $p->branch?->name,
            'is_active' => $p->is_active,
            'notes' => $p->notes,
            'login' => $p->user ? ['phone' => $p->user->phone, 'is_active' => (bool) $p->user->is_active] : null,
        ];
    }
}
