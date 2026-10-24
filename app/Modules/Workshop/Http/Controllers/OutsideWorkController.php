<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Http\Controllers\Concerns\ValidatesMediaUpload;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobOutsideWork;
use App\Modules\Workshop\Models\ServicePartner;
use App\Modules\Workshop\Services\JobSheetException;
use App\Modules\Workshop\Services\OutsideWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff side of outside work on a job: send the car to a washing / wheel-alignment partner,
 * add the photos the partner sent on WhatsApp (from the gallery), "Car is back", cancel.
 */
class OutsideWorkController extends ApiController
{
    use ValidatesMediaUpload;

    public function __construct(protected OutsideWorkService $works) {}

    public function index(JobCard $jobCard): JsonResponse
    {
        $works = JobOutsideWork::where('job_card_id', $jobCard->id)->orderBy('id')->get();

        return $this->success([
            'works' => $works->map(fn (JobOutsideWork $w) => $this->works->present($w))->values(),
            // The partners to choose from, per kind
            'partners' => ServicePartner::where('is_active', true)->forBranch($jobCard->branch_id)->orderBy('name')->get(['id', 'name', 'kind', 'phone'])
                ->groupBy('kind')->map(fn ($g) => $g->map(fn (ServicePartner $p) => ['id' => $p->id, 'name' => $p->name])->values()),
            'kinds' => ServicePartner::KINDS,
        ]);
    }

    public function store(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'kind' => 'required|string|in:' . implode(',', array_keys(ServicePartner::KINDS)),
            'service_partner_id' => 'required|integer',
            'note' => 'nullable|string|max:500',
        ]);

        return $this->guarded(fn () => $this->works->send($jobCard, $data['kind'], (int) $data['service_partner_id'], $request->user(), $data['note'] ?? null), 'Sent to the partner', true);
    }

    public function returned(Request $request, JobCard $jobCard, JobOutsideWork $work): JsonResponse
    {
        abort_unless($work->job_card_id === $jobCard->id, 404);

        return $this->guarded(fn () => $this->works->returned($work, $request->user()), 'The car is back');
    }

    public function cancel(Request $request, JobCard $jobCard, JobOutsideWork $work): JsonResponse
    {
        abort_unless($work->job_card_id === $jobCard->id, 404);

        return $this->guarded(fn () => $this->works->cancel($work, $request->user()), 'Cancelled');
    }

    /**
     * The technician adds what the partner sent on WhatsApp (gallery uploads are fine here).
     */
    public function uploadMedia(Request $request, JobCard $jobCard, JobOutsideWork $work): JsonResponse
    {
        abort_unless($work->job_card_id === $jobCard->id, 404);
        $this->validateMediaUpload($request);

        return $this->guarded(function () use ($request, $work) {
            $this->works->attachMedia($work, $request->file('file'), $request->user(), $request->file('poster'), $request->only(['duration_seconds', 'description', 'captured_at']));

            return $work->fresh();
        }, 'Uploaded', true);
    }

    private function guarded(callable $step, string $message, bool $created = false): JsonResponse
    {
        try {
            $work = $step();
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }
        $data = $this->works->present($work->fresh());

        return $created ? $this->created($data, $message) : $this->success($data, $message);
    }
}
