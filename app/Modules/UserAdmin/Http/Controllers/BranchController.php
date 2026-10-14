<?php

namespace App\Modules\UserAdmin\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Branch;
use App\Modules\UserAdmin\Http\Requests\CreateBranchRequest;
use App\Modules\UserAdmin\Http\Requests\UpdateBranchRequest;
use App\Modules\UserAdmin\Http\Resources\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends ApiController
{
    /**
     * List all branches.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Branch::withCount('users')
            ->when($request->search, fn($q, $search) =>
                $q->where(fn($q) =>
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('city', 'like', "%{$search}%")
                )
            )
            ->when($request->has('is_active'), fn($q) =>
                $q->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy($request->sort_by ?? 'name', $request->sort_order ?? 'asc');

        $branches = $query->paginate($request->per_page ?? 15);

        return $this->paginated($branches);
    }

    /**
     * Create a new branch.
     */
    public function store(CreateBranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->validated());

        return $this->created(
            new BranchResource($branch),
            'Branch created successfully'
        );
    }

    /**
     * Get a specific branch.
     */
    public function show(Branch $branch): JsonResponse
    {
        return $this->success(
            new BranchResource($branch->loadCount('users'))
        );
    }

    /**
     * Update a branch.
     */
    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return $this->success(
            new BranchResource($branch),
            'Branch updated successfully'
        );
    }

    /**
     * Delete a branch.
     */
    public function destroy(Branch $branch): JsonResponse
    {
        if ($branch->users()->count() > 0) {
            return $this->error('Cannot delete branch with assigned users', 400);
        }

        $branch->delete();

        return $this->success(null, 'Branch deleted successfully');
    }

    /**
     * Activate a branch.
     */
    public function activate(Branch $branch): JsonResponse
    {
        $branch->update(['is_active' => true]);

        return $this->success(null, 'Branch activated successfully');
    }

    /**
     * Deactivate a branch.
     */
    public function deactivate(Branch $branch): JsonResponse
    {
        $branch->update(['is_active' => false]);

        return $this->success(null, 'Branch deactivated successfully');
    }

    /**
     * Get users in a branch.
     */
    public function users(Request $request, Branch $branch): JsonResponse
    {
        $users = $branch->users()
            ->with('roles')
            ->when($request->search, fn($q, $search) =>
                $q->where(fn($q) =>
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                )
            )
            ->orderBy('name')
            ->paginate($request->per_page ?? 15);

        return $this->paginated($users);
    }
}
