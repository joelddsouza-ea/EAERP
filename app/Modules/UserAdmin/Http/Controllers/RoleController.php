<?php

namespace App\Modules\UserAdmin\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Role;
use App\Core\Models\Permission;
use App\Modules\UserAdmin\Http\Requests\CreateRoleRequest;
use App\Modules\UserAdmin\Http\Requests\UpdateRoleRequest;
use App\Modules\UserAdmin\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends ApiController
{
    /**
     * List all roles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Role::withCount(['users', 'permissions'])
            ->when($request->search, fn($q, $search) =>
                $q->where('name', 'like', "%{$search}%")
            )
            ->when($request->boolean('system_only'), fn($q) =>
                $q->where('is_system', true)
            )
            ->orderBy('name');

        $roles = $query->paginate($request->per_page ?? 15);

        return $this->paginated($roles);
    }

    /**
     * Create a new role.
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->name,
            'slug' => $request->slug ?? \Str::slug($request->name),
            'description' => $request->description,
            'is_system' => false,
        ]);

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return $this->created(
            new RoleResource($role->load('permissions')),
            'Role created successfully'
        );
    }

    /**
     * Get a specific role.
     */
    public function show(Role $role): JsonResponse
    {
        return $this->success(
            new RoleResource($role->load('permissions'))
        );
    }

    /**
     * Update a role.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        // Prevent updating system roles' core properties
        if ($role->is_system && ($request->has('slug') || $request->has('name'))) {
            return $this->error('System role core properties cannot be modified', 400);
        }

        $role->update($request->only(['name', 'description']));

        if ($request->has('permissions')) {
            $role->syncPermissions($request->permissions);
        }

        return $this->success(
            new RoleResource($role->load('permissions')),
            'Role updated successfully'
        );
    }

    /**
     * Delete a role.
     */
    public function destroy(Role $role): JsonResponse
    {
        if ($role->is_system) {
            return $this->error('System roles cannot be deleted', 400);
        }

        if ($role->users()->count() > 0) {
            return $this->error('Cannot delete role with assigned users', 400);
        }

        $role->delete();

        return $this->success(null, 'Role deleted successfully');
    }

    /**
     * Get role permissions.
     */
    public function permissions(Role $role): JsonResponse
    {
        return $this->success([
            'role' => $role->only(['id', 'name', 'slug']),
            'permissions' => $role->permissions->pluck('slug'),
        ]);
    }

    /**
     * Sync role permissions.
     */
    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,slug',
        ]);

        $role->syncPermissions($request->permissions);

        return $this->success(
            new RoleResource($role->fresh()->load('permissions')),
            'Permissions updated successfully'
        );
    }

    /**
     * Get all permissions grouped by module.
     */
    public function allPermissions(): JsonResponse
    {
        $permissions = Permission::all()
            ->groupBy('module')
            ->map(fn($group) => $group->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'description' => $p->description,
            ])->values());

        return $this->success($permissions);
    }
}
