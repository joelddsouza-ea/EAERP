<?php

namespace App\Modules\Workshop\Http\Middleware;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\JobLifecycleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a successful change to a job card (intake, assignments, job sheets, issues,
 * estimate, checklist, billing...), let the job move on to the stage the facts allow.
 */
class SyncJobLifecycle
{
    public function __construct(protected JobLifecycleService $lifecycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || !$response->isSuccessful()) {
            return $response;
        }

        $jobCard = $request->route('jobCard') ?? $request->route('job_card');

        if ($jobCard instanceof JobCard && !$jobCard->isClosed()) {
            $this->lifecycle->sync($jobCard);
        }

        return $response;
    }
}
