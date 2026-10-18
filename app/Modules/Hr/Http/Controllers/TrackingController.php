<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Hr\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live staff location: the phone's uploads (the employee's own) and the owner / manager map.
 */
class TrackingController extends ApiController
{
    public function __construct(protected LocationService $locations) {}

    /**
     * A batch of positions from the phone (queued while offline, sent when back online).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => 'nullable|in:web,android',
            'gap' => 'nullable|in:location_off,permission_denied,app_closed',
            'points' => 'present|array|max:' . LocationService::MAX_BATCH,
            'points.*.lat' => 'required|numeric|between:-90,90',
            'points.*.lng' => 'required|numeric|between:-180,180',
            'points.*.accuracy' => 'nullable|numeric|min:0',
            'points.*.speed' => 'nullable|numeric',
            'points.*.heading' => 'nullable|numeric',
            'points.*.recorded_at' => 'required|date',
            'points.*.battery' => 'nullable|numeric|between:0,100',
            'points.*.charging' => 'nullable|boolean',
        ]);

        return $this->success($this->locations->ingest($request->user(), $data['points'], $data['source'] ?? 'web', $data['gap'] ?? null));
    }

    public function consent(Request $request): JsonResponse
    {
        $this->locations->consent($request->user());

        return $this->success($this->locations->clientConfig($request->user()));
    }

    public function live(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;
        if ($branchId && !$request->user()->isOwner() && !in_array($branchId, $request->user()->getAccessibleBranchIds())) {
            return $this->error('You do not have access to this branch.', 403);
        }

        return $this->success($this->locations->live($request->user(), $branchId));
    }

    public function trail(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        if (!$viewer->isOwner() && $user->id !== $viewer->id && !array_intersect($user->branches()->pluck('branches.id')->all(), $viewer->getAccessibleBranchIds())) {
            return $this->error('You do not have access to this employee.', 403);
        }
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? today()->toDateString();

        return $this->success($this->locations->trail($user, $date));
    }
}
