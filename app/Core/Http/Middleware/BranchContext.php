<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Core\Services\BranchContextService;

class BranchContext
{
    public function __construct(
        protected BranchContextService $branchContext
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Set user in context
            $this->branchContext->setUser($user);

            // Check for branch override in header or query
            $branchId = $request->header('X-Branch-Id') ?? $request->query('branch_id');

            if ($branchId) {
                // Verify user has access to the specified branch
                if (!$user->hasAccessToBranch((int) $branchId)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have access to this branch.',
                    ], 403);
                }

                $this->branchContext->setCurrentBranchId((int) $branchId);
            } else {
                // Default to user's primary branch
                $primaryBranch = $user->primaryBranch();
                if ($primaryBranch) {
                    $this->branchContext->setCurrentBranchId($primaryBranch->id);
                }
            }
        }

        return $next($request);
    }
}
