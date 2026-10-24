<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Http\Controllers\Concerns\ValidatesMediaUpload;
use App\Modules\Workshop\Http\Resources\JobSheetItemResource;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobOutsideWork;
use App\Modules\Workshop\Services\JobSheetService;
use App\Modules\Workshop\Services\JobSheetException;
use App\Modules\Workshop\Services\OutsideWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The outside partner's login (washing, wheel alignment & balancing): the cars sent to them,
 * photos/videos of their work, "Done". They see the car, never the customer or prices.
 */
class PartnerPortalController extends ApiController
{
    use ValidatesMediaUpload;

    public function __construct(protected OutsideWorkService $works) {}

    public function index(Request $request): JsonResponse
    {
        $works = JobOutsideWork::whereHas('partner', fn ($q) => $q->where('user_id', $request->user()->id))
            ->where(fn ($q) => $q->whereIn('status', JobOutsideWork::OPEN)->orWhere('updated_at', '>=', now()->subDays(7)))
            ->where('status', '!=', JobOutsideWork::CANCELLED)
            ->orderByRaw("FIELD(status, 'sent', 'done', 'returned')")
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->success($works->map(fn (JobOutsideWork $w) => $this->works->present($w, true))->values());
    }

    public function show(Request $request, JobOutsideWork $work): JsonResponse
    {
        $this->own($request, $work);

        return $this->success($this->works->present($work, true) + [
            // The washing points to fill, with the photos / videos each needs (master settings)
            'items' => JobSheetItemResource::collection($this->works->partnerItems($work))->resolve($request),
        ]);
    }

    public function uploadItemMedia(Request $request, JobOutsideWork $work, JobCardChecklistItem $item, JobSheetService $sheets): JsonResponse
    {
        $this->own($request, $work);
        $this->validateMediaUpload($request);

        try {
            $sheets->asPartner($work, $item, fn () => $sheets->attachMedia(
                $item, $request->file('file'), $request->user(), $request->file('poster'), $request->only(['duration_seconds', 'description', 'captured_at']),
            ));
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }

        return $this->created((new JobSheetItemResource($item->fresh('media')))->resolve($request), 'Uploaded');
    }

    public function updateItem(Request $request, JobOutsideWork $work, JobCardChecklistItem $item, JobSheetService $sheets): JsonResponse
    {
        $this->own($request, $work);
        $data = $request->validate([
            'status' => 'sometimes|in:pending,done,not_applicable',
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $updated = $sheets->asPartner($work, $item, fn () => $sheets->updateItem($item, $data, $request->user()));
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }

        return $this->success((new JobSheetItemResource($updated->fresh('media')))->resolve($request), 'Saved');
    }

    public function uploadMedia(Request $request, JobOutsideWork $work): JsonResponse
    {
        $this->own($request, $work);
        $this->validateMediaUpload($request);

        try {
            if ($work->status !== JobOutsideWork::SENT) {
                throw new JobSheetException('This car is no longer with you.');
            }
            $this->works->attachMedia($work, $request->file('file'), $request->user(), $request->file('poster'), $request->only(['duration_seconds', 'description', 'captured_at']));
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }

        return $this->created($this->works->present($work->fresh(), true), 'Uploaded');
    }

    public function done(Request $request, JobOutsideWork $work): JsonResponse
    {
        $this->own($request, $work);
        $data = $request->validate(['note' => 'nullable|string|max:500']);

        try {
            $this->works->done($work, $request->user(), $data['note'] ?? null);
        } catch (JobSheetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }

        return $this->success($this->works->present($work->fresh(), true), 'Marked done. The workshop has been told.');
    }

    private function own(Request $request, JobOutsideWork $work): void
    {
        abort_unless($work->partner?->user_id === $request->user()->id, 404);
    }
}
