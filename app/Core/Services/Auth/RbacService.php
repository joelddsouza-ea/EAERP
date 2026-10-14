<?php

namespace App\Core\Services\Auth;

use App\Core\Models\User;
use App\Core\Models\Role;
use App\Core\Models\Permission;
use Illuminate\Support\Facades\Cache;

/**
 * Role-Based Access Control Service
 *
 * Manages role and permission checking with caching for performance.
 */
class RbacService
{
    protected const CACHE_TTL = 3600; // 1 hour
    protected const CACHE_PREFIX = 'rbac.';

    /**
     * Get all permissions for a user (cached).
     */
    public function getUserPermissions(User $user): array
    {
        $cacheKey = self::CACHE_PREFIX . "user.{$user->id}.permissions";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $user->getAllPermissions();
        });
    }

    /**
     * Get all roles for a user (cached).
     */
    public function getUserRoles(User $user): array
    {
        $cacheKey = self::CACHE_PREFIX . "user.{$user->id}.roles";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $user->roles->pluck('slug')->toArray();
        });
    }

    /**
     * Check if user has a permission.
     */
    public function hasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $this->getUserPermissions($user));
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAnyPermission(User $user, array $permissions): bool
    {
        return !empty(array_intersect($permissions, $this->getUserPermissions($user)));
    }

    /**
     * Check if user has all of the given permissions.
     */
    public function hasAllPermissions(User $user, array $permissions): bool
    {
        return empty(array_diff($permissions, $this->getUserPermissions($user)));
    }

    /**
     * Check if user has a role.
     */
    public function hasRole(User $user, string $role): bool
    {
        return in_array($role, $this->getUserRoles($user));
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(User $user, array $roles): bool
    {
        return !empty(array_intersect($roles, $this->getUserRoles($user)));
    }

    /**
     * Assign role to user.
     */
    public function assignRole(User $user, string|Role $role): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->firstOrFail();
        }

        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->clearUserCache($user);
    }

    /**
     * Remove role from user.
     */
    public function removeRole(User $user, string|Role $role): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->firstOrFail();
        }

        $user->roles()->detach($role->id);
        $this->clearUserCache($user);
    }

    /**
     * Sync user roles.
     */
    public function syncRoles(User $user, array $roleSlugs): void
    {
        $roleIds = Role::whereIn('slug', $roleSlugs)->pluck('id')->toArray();
        $user->roles()->sync($roleIds);
        $this->clearUserCache($user);
    }

    /**
     * Get permissions for a module.
     */
    public function getModulePermissions(string $module): array
    {
        $cacheKey = self::CACHE_PREFIX . "module.{$module}.permissions";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($module) {
            return Permission::forModule($module)
                ->pluck('slug', 'name')
                ->toArray();
        });
    }

    /**
     * Create a new permission.
     */
    public function createPermission(string $module, string $resource, string $action, ?string $description = null): Permission
    {
        $slug = Permission::makeSlug($module, $resource, $action);
        $name = ucfirst($action) . ' ' . str_replace('-', ' ', $resource);

        return Permission::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'module' => $module,
                'description' => $description,
            ]
        );
    }

    /**
     * Register module permissions.
     */
    public function registerModulePermissions(string $module, array $resources): void
    {
        $actions = ['view', 'create', 'update', 'delete'];

        foreach ($resources as $resource => $description) {
            if (is_int($resource)) {
                $resource = $description;
                $description = null;
            }

            foreach ($actions as $action) {
                $this->createPermission($module, $resource, $action, $description);
            }
        }

        // Clear module permission cache
        Cache::forget(self::CACHE_PREFIX . "module.{$module}.permissions");
    }

    /**
     * Clear user's RBAC cache.
     */
    public function clearUserCache(User $user): void
    {
        Cache::forget(self::CACHE_PREFIX . "user.{$user->id}.permissions");
        Cache::forget(self::CACHE_PREFIX . "user.{$user->id}.roles");
    }

    /**
     * Clear all RBAC caches.
     */
    public function clearAllCache(): void
    {
        // Clear pattern-based keys if using Redis
        // For file/database cache, we need to track keys
        Cache::flush(); // Be careful with this in production
    }

    /**
     * Get default permissions for a role template.
     */
    public function getDefaultRolePermissions(string $roleSlug): array
    {
        return match ($roleSlug) {
            Role::OWNER => ['*'], // All permissions
            Role::MANAGER => [
                'workshop.job-cards.*',
                'workshop.customers.*',
                'workshop.vehicles.*',
                'billing.invoices.*',
                'billing.payments.*',
                'user-admin.users.view',
            ],
            Role::SERVICE_ADVISOR => [
                'workshop.job-cards.*',
                'workshop.customers.*',
                'workshop.vehicles.*',
                'workshop.technicians.view',
                'workshop.bays.*',
                'billing.invoices.view',
                'billing.invoices.create',
            ],
            Role::TECHNICIAN => [
                'workshop.job-cards.view',
                'workshop.job-cards.update',
                'workshop.checklist.update',
            ],
            Role::BACKOFFICE => [
                'billing.invoices.*',
                'billing.payments.*',
                'workshop.customers.view',
                'workshop.job-cards.view',
            ],
            Role::MARKETING => [
                'crm.leads.*',
                'workshop.customers.*',
            ],
            default => [],
        };
    }
}
