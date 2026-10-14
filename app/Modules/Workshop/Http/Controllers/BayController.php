<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\Bay;
use App\Modules\Workshop\Services\JobCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BayController extends ApiController
{
    public function __construct(
        protected JobCardService $jobCardService
    ) {}

    /**
     * List all bays.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Bay::with(['currentJobCards.vehicle', 'currentJobCards.technician'])
            ->when($request->type, fn($q, $type) =>
                $q->where('type', $type)
            )
            ->when($request->has('is_active'), fn($q) =>
                $q->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy('name');

        $bays = $query->get();

        return $this->success($bays->map(function ($bay) {
            return [
                'id' => $bay->id,
                'name' => $bay->name,
                'code' => $bay->code,
                'type' => $bay->type,
                'description' => $bay->description,
                'is_active' => $bay->is_active,
                'is_occupied' => $bay->isOccupied(),
                'current_job' => $bay->getCurrentJobCard(),
            ];
        }));
    }

    /**
     * Create a new bay.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:10',
            'type' => 'required|in:general,detailing,body_shop,ac,electrical',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $branchId = app('branch.context')->getCurrentBranchId();

        // Check unique code within branch
        if (Bay::where('branch_id', $branchId)->where('code', $request->code)->exists()) {
            return $this->error('Bay code already exists in this branch', 422);
        }

        $bay = Bay::create([
            'branch_id' => $branchId,
            ...$request->all(),
        ]);

        return $this->created($bay, 'Bay created successfully');
    }

    /**
     * Get a specific bay.
     */
    public function show(Bay $bay): JsonResponse
    {
        $bay->load(['currentJobCards.vehicle', 'currentJobCards.technician', 'currentJobCards.customer']);

        return $this->success([
            'id' => $bay->id,
            'name' => $bay->name,
            'code' => $bay->code,
            'type' => $bay->type,
            'description' => $bay->description,
            'is_active' => $bay->is_active,
            'is_occupied' => $bay->isOccupied(),
            'current_job' => $bay->getCurrentJobCard(),
        ]);
    }

    /**
     * Update a bay.
     */
    public function update(Request $request, Bay $bay): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:10',
            'type' => 'sometimes|in:general,detailing,body_shop,ac,electrical',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Check unique code within branch if changing
        if ($request->has('code') && $request->code !== $bay->code) {
            if (Bay::where('branch_id', $bay->branch_id)
                ->where('code', $request->code)
                ->where('id', '!=', $bay->id)
                ->exists()
            ) {
                return $this->error('Bay code already exists in this branch', 422);
            }
        }

        $bay->update($request->all());

        return $this->success($bay, 'Bay updated successfully');
    }

    /**
     * Delete a bay.
     */
    public function destroy(Bay $bay): JsonResponse
    {
        if ($bay->isOccupied()) {
            return $this->error('Cannot delete bay with active job card', 400);
        }

        $bay->delete();

        return $this->success(null, 'Bay deleted successfully');
    }

    /**
     * Get all bays status for current branch.
     */
    public function allStatus(): JsonResponse
    {
        $branchId = app('branch.context')->getCurrentBranchId();
        $status = $this->jobCardService->getBayStatus($branchId);

        return $this->success($status);
    }
}
