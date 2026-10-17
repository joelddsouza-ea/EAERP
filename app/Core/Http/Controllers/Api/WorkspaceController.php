<?php

namespace App\Core\Http\Controllers\Api;

use App\Core\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The header search box and the "My Day" screen.
 */
class WorkspaceController extends ApiController
{
    public function __construct(protected WorkspaceService $workspace) {}

    public function search(Request $request): JsonResponse
    {
        return $this->success($this->workspace->search($request->user(), (string) $request->query('q', '')));
    }

    public function myDay(Request $request): JsonResponse
    {
        return $this->success($this->workspace->myDay($request->user()));
    }
}
