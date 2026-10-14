<?php

namespace App\Modules\UserAdmin\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Core\Models\AuditLog;
use App\Modules\UserAdmin\Http\Requests\CreateUserRequest;
use App\Modules\UserAdmin\Http\Requests\UpdateUserRequest;
use App\Modules\UserAdmin\Http\Resources\UserResource;
use App\Modules\UserAdmin\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function __construct(
        protected UserService $userService
    ) {}

    /**
     * List all users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['roles', 'branches'])
            ->when($request->search, fn($q, $search) =>
                $q->where(fn($q) =>
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                )
            )
            ->when($request->role, fn($q, $role) =>
                $q->whereHas('roles', fn($q) => $q->where('slug', $role))
            )
            ->when($request->branch_id, fn($q, $branchId) =>
                $q->whereHas('branches', fn($q) => $q->where('branches.id', $branchId))
            )
            ->when($request->has('is_active'), fn($q) =>
                $q->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy($request->sort_by ?? 'name', $request->sort_order ?? 'asc');

        $users = $query->paginate($request->per_page ?? 15);

        return $this->paginated($users);
    }

    /**
     * Create a new user.
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser($request->validated());

        return $this->created(
            new UserResource($user->load(['roles', 'branches'])),
            'User created successfully'
        );
    }

    /**
     * Get a specific user.
     */
    public function show(User $user): JsonResponse
    {
        return $this->success(
            new UserResource($user->load(['roles', 'branches']))
        );
    }

    /**
     * Update a user.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->updateUser($user, $request->validated());

        return $this->success(
            new UserResource($user->load(['roles', 'branches'])),
            'User updated successfully'
        );
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            return $this->error('You cannot delete your own account', 400);
        }

        $user->delete();

        return $this->success(null, 'User deleted successfully');
    }

    /**
     * Activate a user.
     */
    public function activate(User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        return $this->success(null, 'User activated successfully');
    }

    /**
     * Deactivate a user.
     */
    public function deactivate(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return $this->error('You cannot deactivate your own account', 400);
        }

        $user->update(['is_active' => false]);

        // Revoke all tokens
        $user->tokens()->delete();

        return $this->success(null, 'User deactivated successfully');
    }

    /**
     * Reset user password.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'password' => bcrypt($request->password),
        ]);

        // Revoke all tokens
        $user->tokens()->delete();

        return $this->success(null, 'Password reset successfully');
    }

    /**
     * Get user activity log.
     */
    public function activity(Request $request, User $user): JsonResponse
    {
        $logs = AuditLog::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return $this->paginated($logs);
    }
}
