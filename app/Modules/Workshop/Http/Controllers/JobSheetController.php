<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Http\Resources\JobCardMediaResource;
use App\Modules\Workshop\Http\Resources\JobSheetItemResource;
use App\Modules\Workshop\Http\Resources\JobSheetResource;
use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Services\JobSheetException;
use App\Modules\Workshop\Services\JobSheetRuleEngine;
use App\Modules\Workshop\Services\JobSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Technician job sheets and pre-delivery checklists on a job card.
 */
class JobSheetController extends ApiController
{
    private const SHEET_RELATIONS = ['items.media', 'items.completedBy', 'template', 'completedBy'];

    public function __construct(
        protected JobSheetService $sheets,
        protected JobSheetRuleEngine $rules,
    ) {}

    public function index(JobCard $jobCard): JsonResponse
    {
        $sheets = $jobCard->sheets()->with(self::SHEET_RELATIONS)->orderBy('id')->get();

        return $this->success(JobSheetResource::collection($sheets));
    }

    public function show(JobCard $jobCard, JobCardChecklist $sheet): JsonResponse
    {
        return $this->success(new JobSheetResource($sheet->load(self::SHEET_RELATIONS)));
    }

    /**
     * Shortcut to the advisor's pre-delivery checklist.
     */
    public function preDelivery(JobCard $jobCard): JsonResponse
    {
        $sheet = $jobCard->sheets()
            ->where('sheet_kind', ChecklistTemplate::KIND_PRE_DELIVERY)
            ->with(self::SHEET_RELATIONS)
            ->first();

        return $sheet ? $this->success(new JobSheetResource($sheet)) : $this->notFound('This job card has no pre-delivery checklist.');
    }

    public function updateItem(Request $request, JobCard $jobCard, JobCardChecklist $sheet, JobCardChecklistItem $item): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(JobCardChecklistItem::STATUSES)],
            'needs_attention' => 'sometimes|boolean',
            'remarks' => 'nullable|string|max:1000',
            'value' => 'nullable',
        ]);

        // Pre-delivery answers need the pre-delivery permission, not just job-sheet access
        if ($sheet->sheet_kind === ChecklistTemplate::KIND_PRE_DELIVERY && !$request->user()->hasPermission('workshop.pre-delivery.update')) {
            return $this->forbidden('You do not have permission to fill the pre-delivery checklist.');
        }

        $item->setRelation('jobCardChecklist', $sheet->setRelation('jobCard', $jobCard));
        $item = $this->sheets->updateItem($item, $data, $request->user());

        return $this->success([
            'item' => new JobSheetItemResource($item),
            'progress' => $this->rules->progress($sheet->load('items.media')),
        ], 'Activity updated');
    }

    public function addItem(Request $request, JobCard $jobCard, JobCardChecklist $sheet): JsonResponse
    {
        $data = $request->validate([
            'item_name' => 'required|string|max:255',
            'section_name' => 'nullable|string|max:255',
            'input_type' => ['nullable', Rule::in(ChecklistItem::INPUT_TYPES)],
        ]);

        $sheet->setRelation('jobCard', $jobCard);
        $item = $this->sheets->addItem($sheet, $data, $request->user());

        return $this->created(new JobSheetItemResource($item), 'Row added');
    }

    public function uploadMedia(Request $request, JobCard $jobCard, JobCardChecklist $sheet, JobCardChecklistItem $item): JsonResponse
    {
        $maxVideo = (int) config('workshop.job_sheets.max_video_kb');
        $maxPhoto = (int) config('workshop.job_sheets.max_photo_kb');

        $request->validate([
            'file' => [
                'required', 'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm',
                'max:' . max($maxVideo, $maxPhoto),
            ],
            'poster' => 'nullable|image|max:2048',
            'duration_seconds' => 'nullable|numeric|min:0|max:' . config('workshop.job_sheets.max_video_seconds'),
            'description' => 'nullable|string|max:255',
            'captured_at' => 'nullable|date',
        ]);

        $file = $request->file('file');
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
        if (!$isVideo && $file->getSize() > $maxPhoto * 1024) {
            return $this->validationError(['file' => ['Photos may not be larger than ' . round($maxPhoto / 1024) . ' MB.']]);
        }

        $item->setRelation('jobCardChecklist', $sheet->setRelation('jobCard', $jobCard));
        $media = $this->sheets->attachMedia(
            $item,
            $file,
            $request->user(),
            $request->file('poster'),
            $request->only(['duration_seconds', 'description', 'captured_at'])
        );

        $item->load('media');

        return $this->created([
            'media' => new JobCardMediaResource($media),
            'media_shortfall' => $this->rules->mediaShortfall($item),
        ], 'Uploaded');
    }

    public function deleteMedia(Request $request, JobCard $jobCard, JobCardChecklist $sheet, JobCardChecklistItem $item, JobCardMedia $media): JsonResponse
    {
        if ($media->attachable_type !== 'sheet_item' || $media->attachable_id !== $item->id) {
            return $this->notFound('Media not found on this activity.');
        }

        $item->setRelation('jobCardChecklist', $sheet->setRelation('jobCard', $jobCard));
        $reverted = $this->sheets->deleteMedia($media, $item, $request->user());

        return $this->success([
            'item' => new JobSheetItemResource($item->fresh(['media', 'completedBy'])),
            'reverted_to_pending' => $reverted,
        ], $reverted ? 'Deleted — the activity is no longer marked done' : 'Deleted');
    }

    public function updateMedia(Request $request, JobCard $jobCard, JobCardMedia $media): JsonResponse
    {
        $data = $request->validate([
            'description' => 'sometimes|nullable|string|max:255',
            'customer_visible' => 'sometimes|boolean',
        ]);

        $media->update($data);

        return $this->success(new JobCardMediaResource($media), 'Media updated');
    }

    public function submit(Request $request, JobCard $jobCard, JobCardChecklist $sheet): JsonResponse
    {
        $request->validate([
            'override' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        $sheet->setRelation('jobCard', $jobCard);
        $this->sheets->submit($sheet, $request->user(), $request->boolean('override'), $request->notes);

        return $this->success(new JobSheetResource($sheet->fresh(self::SHEET_RELATIONS)), 'Sheet submitted');
    }

    public function reopen(Request $request, JobCard $jobCard, JobCardChecklist $sheet): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:500']);

        $sheet->setRelation('jobCard', $jobCard);
        $this->sheets->reopen($sheet, $request->user(), $request->notes);

        return $this->success(new JobSheetResource($sheet->fresh(self::SHEET_RELATIONS)), 'Sheet reopened');
    }

    /**
     * Add sheets matching the current plan / service type; drop untouched ones that no longer match.
     */
    public function sync(JobCard $jobCard): JsonResponse
    {
        if ($jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }

        return $this->success($this->sheets->sync($jobCard), 'Job sheets synced');
    }

    /**
     * Serve a media file through a signed URL (no bearer token; supports range requests for video).
     */
    public function stream(Request $request, JobCardMedia $media): BinaryFileResponse
    {
        $path = $request->boolean('thumb') ? $media->thumbnail_path : $media->file_path;
        $disk = Storage::disk($media->disk);

        abort_unless($path && $disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $request->boolean('thumb') ? 'image/jpeg' : $media->mime,
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
