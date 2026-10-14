<?php

namespace App\Modules\UserAdmin\Services;

use App\Core\Models\User;
use App\Core\Models\Role;
use App\Core\Services\Auth\RbacService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function __construct(
        protected RbacService $rbacService
    ) {}

    /**
     * Create a new user.
     */
    public function createUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // Create user
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Assign roles
            if (!empty($data['roles'])) {
                $this->rbacService->syncRoles($user, $data['roles']);
            }

            // Assign branches
            if (!empty($data['branches'])) {
                $this->assignBranches($user, $data['branches'], $data['primary_branch_id']);
            }

            return $user;
        });
    }

    /**
     * Update an existing user.
     */
    public function updateUser(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // Update basic info
            $updateData = collect($data)->only(['name', 'email', 'phone', 'is_active'])->toArray();

            if (!empty($updateData)) {
                $user->update($updateData);
            }

            // Update roles
            if (isset($data['roles'])) {
                $this->rbacService->syncRoles($user, $data['roles']);
            }

            // Update branches
            if (isset($data['branches'])) {
                $primaryBranchId = $data['primary_branch_id'] ?? $data['branches'][0];
                $this->assignBranches($user, $data['branches'], $primaryBranchId);
            } elseif (isset($data['primary_branch_id'])) {
                // Just update primary branch
                $this->updatePrimaryBranch($user, $data['primary_branch_id']);
            }

            return $user->fresh();
        });
    }

    /**
     * Assign branches to user.
     */
    protected function assignBranches(User $user, array $branchIds, int $primaryBranchId): void
    {
        $syncData = [];

        foreach ($branchIds as $branchId) {
            $syncData[$branchId] = [
                'is_primary' => $branchId == $primaryBranchId,
            ];
        }

        $user->branches()->sync($syncData);
    }

    /**
     * Update primary branch.
     */
    protected function updatePrimaryBranch(User $user, int $primaryBranchId): void
    {
        // Reset all to non-primary
        $user->branches()->updateExistingPivot(
            $user->branches->pluck('id')->toArray(),
            ['is_primary' => false]
        );

        // Set new primary
        $user->branches()->updateExistingPivot($primaryBranchId, ['is_primary' => true]);
    }

    /**
     * Get users by role.
     */
    public function getUsersByRole(string $roleSlug): \Illuminate\Database\Eloquent\Collection
    {
        return User::whereHas('roles', fn($q) => $q->where('slug', $roleSlug))
            ->active()
            ->get();
    }

    /**
     * Get users for a branch.
     */
    public function getUsersForBranch(int $branchId): \Illuminate\Database\Eloquent\Collection
    {
        return User::forBranch($branchId)
            ->with('roles')
            ->active()
            ->get();
    }

    /**
     * Get technicians for a branch.
     */
    public function getTechnicians(int $branchId): \Illuminate\Database\Eloquent\Collection
    {
        return User::forBranch($branchId)
            ->withRole(Role::TECHNICIAN)
            ->active()
            ->get();
    }

    /**
     * Get service advisors for a branch.
     */
    public function getServiceAdvisors(int $branchId): \Illuminate\Database\Eloquent\Collection
    {
        return User::forBranch($branchId)
            ->withRole(Role::SERVICE_ADVISOR)
            ->active()
            ->get();
    }
}
