<?php

namespace App\Core\Services;

use App\Core\Models\User;
use App\Core\Models\Branch;

/**
 * Service to manage branch context for the current request.
 *
 * This service is registered as a singleton and tracks:
 * - Current authenticated user
 * - Current selected branch
 * - User's accessible branches
 */
class BranchContextService
{
    protected ?User $user = null;
    protected ?int $currentBranchId = null;
    protected ?array $accessibleBranchIds = null;

    /**
     * Set the current user.
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->accessibleBranchIds = null; // Reset cache
    }

    /**
     * Get the current user.
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Set the current branch ID.
     */
    public function setCurrentBranchId(int $branchId): void
    {
        $this->currentBranchId = $branchId;
    }

    /**
     * Get the current branch ID.
     */
    public function getCurrentBranchId(): ?int
    {
        return $this->currentBranchId;
    }

    /**
     * Get the current branch.
     */
    public function getCurrentBranch(): ?Branch
    {
        if (!$this->currentBranchId) {
            return null;
        }

        return Branch::find($this->currentBranchId);
    }

    /**
     * Check if user has access to all branches (Owner role).
     */
    public function hasAllBranchesAccess(): bool
    {
        return $this->user?->isOwner() ?? false;
    }

    /**
     * Get IDs of branches the current user can access.
     */
    public function getAccessibleBranchIds(): array
    {
        if ($this->accessibleBranchIds !== null) {
            return $this->accessibleBranchIds;
        }

        if (!$this->user) {
            return [];
        }

        $this->accessibleBranchIds = $this->user->getAccessibleBranchIds();

        return $this->accessibleBranchIds;
    }

    /**
     * Check if the current user can access a specific branch.
     */
    public function canAccessBranch(int $branchId): bool
    {
        if ($this->hasAllBranchesAccess()) {
            return true;
        }

        return in_array($branchId, $this->getAccessibleBranchIds());
    }

    /**
     * Get all branches the user can access.
     */
    public function getAccessibleBranches()
    {
        if ($this->hasAllBranchesAccess()) {
            return Branch::active()->get();
        }

        return Branch::active()
            ->whereIn('id', $this->getAccessibleBranchIds())
            ->get();
    }
}
