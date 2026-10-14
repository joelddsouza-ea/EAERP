<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\JobCardObservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Digital job sheets: creating them from masters, recording activity results and
 * media, and submitting/reopening sheets.
 */
class JobSheetService
{
    /**
     * Job statuses in which each kind of sheet can be edited.
     */
    private const EDITABLE_STATUSES = [
        'work' => ['intake_in_progress', 'work_in_progress', 'awaiting_parts', 'awaiting_approval', 'post_work_trial'],
        ChecklistTemplate::KIND_PRE_DELIVERY => ['post_work_trial', 'wash_pending', 'ready_for_delivery'],
        ChecklistTemplate::KIND_OBSERVATION_REPORT => ['intake_in_progress', 'work_in_progress', 'awaiting_parts', 'awaiting_approval', 'post_work_trial', 'wash_pending'],
    ];

    public function __construct(
        protected JobSheetRuleEngine $rules,
        protected FileStorageService $files,
    ) {}

    /**
     * Templates that apply to a job card: by plan, by service type, or to every job.
     */
    public function matchingTemplates(JobCard $jobCard): Collection
    {
        return ChecklistTemplate::query()
            ->where('is_active', true)
            ->where(function (Builder $q) use ($jobCard) {
                $q->where('applies_to_all', true);
                if ($jobCard->package_id) {
                    $q->orWhereHas('packages', fn (Builder $p) => $p->where('service_packages.id', $jobCard->package_id));
                }
                if ($jobCard->service_type) {
                    $q->orWhere('service_type', $jobCard->service_type);
                }
            })
            ->with(['sections.items' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Create the job card's sheets from matching templates (idempotent per template).
     *
     * @return JobCardChecklist[] the sheets created
     */
    public function instantiateFor(JobCard $jobCard): array
    {
        $existing = $jobCard->checklists()->pluck('template_id')->filter()->all();
        $created = [];

        foreach ($this->matchingTemplates($jobCard) as $template) {
            if (in_array($template->id, $existing, true)) {
                continue;
            }
            $created[] = $this->instantiateTemplate($jobCard, $template);
        }

        return $created;
    }

    /**
     * Add newly matching sheets and drop untouched sheets that no longer match
     * (after a plan or service-type change).
     */
    public function sync(JobCard $jobCard): array
    {
        $matchingIds = $this->matchingTemplates($jobCard)->pluck('id')->all();
        $removed = [];

        $stale = $jobCard->checklists()
            ->whereNotNull('template_id')
            ->whereNotIn('template_id', $matchingIds)
            ->with('items.media')
            ->get();

        foreach ($stale as $sheet) {
            if ($this->isUntouched($sheet)) {
                $removed[] = $sheet->name;
                $sheet->delete();
            }
        }

        $added = array_map(fn ($s) => $s->name, $this->instantiateFor($jobCard));

        return ['added' => $added, 'removed' => $removed];
    }

    public function isUntouched(JobCardChecklist $sheet): bool
    {
        return $sheet->status !== JobCardChecklist::STATUS_COMPLETED
            && $sheet->items->every(fn ($i) => $i->status === JobCardChecklistItem::STATUS_PENDING
                && !$i->needs_attention
                && $i->value === null
                && empty($i->remarks)
                && $i->media->isEmpty());
    }

    protected function instantiateTemplate(JobCard $jobCard, ChecklistTemplate $template): JobCardChecklist
    {
        return DB::transaction(function () use ($jobCard, $template) {
            $sheet = JobCardChecklist::create([
                'job_card_id' => $jobCard->id,
                'template_id' => $template->id,
                'sheet_kind' => $template->sheet_kind,
                'name' => $template->name,
                'status' => JobCardChecklist::STATUS_PENDING,
            ]);

            $rows = [];
            $now = now();

            foreach ($template->sections as $section) {
                foreach ($section->items as $item) {
                    if (!$item->appliesToPackage($jobCard->package_id)) {
                        continue;
                    }
                    $rows[] = [
                        'job_card_checklist_id' => $sheet->id,
                        'checklist_item_id' => $item->id,
                        'status' => JobCardChecklistItem::STATUS_PENDING,
                        'section_name' => $section->name,
                        'item_name' => $item->item_name,
                        'input_type' => $item->input_type,
                        'media_requirement' => $item->media_requirement,
                        'min_media_count' => max(1, (int) $item->min_media_count),
                        'na_allowed' => $item->na_allowed,
                        'expected_answer' => $item->expected_answer,
                        'is_required' => $item->is_required,
                        'sort_order' => $section->sort_order * 1000 + $item->sort_order,
                        'is_completed' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                JobCardChecklistItem::insert($chunk);
            }

            return $sheet;
        });
    }

    /**
     * Record an activity result (✓ done / — N/A / ✗ not done / ⚠ attention, remarks, value).
     *
     * @throws JobSheetException
     */
    public function updateItem(JobCardChecklistItem $item, array $data, User $user): JobCardChecklistItem
    {
        $sheet = $item->jobCardChecklist;
        $this->assertEditable($sheet, $user);
        $item->loadMissing('media');

        $status = $data['status'] ?? $item->status;
        $needsAttention = array_key_exists('needs_attention', $data) ? (bool) $data['needs_attention'] : $item->needs_attention;
        $remarks = array_key_exists('remarks', $data) ? $this->nullIfBlank($data['remarks']) : $item->remarks;
        $value = array_key_exists('value', $data) ? $data['value'] : null;

        $errors = [];

        if ($status === JobCardChecklistItem::STATUS_NOT_APPLICABLE && !$item->na_allowed) {
            $errors['status'][] = 'This activity cannot be marked N/A.';
        }

        if ($status === JobCardChecklistItem::STATUS_DONE && $item->input_type === 'tick' && ($shortfall = $this->rules->mediaShortfall($item))) {
            $errors['media'][] = ucfirst($shortfall) . ' before marking this activity done.';
        }

        if (($status === JobCardChecklistItem::STATUS_NOT_DONE || $needsAttention) && !$remarks) {
            $errors['remarks'][] = 'Add a remark explaining the problem.';
        }

        $valueColumns = [];
        if (array_key_exists('value', $data)) {
            [$valueColumns, $valueErrors] = $this->normalizeValue($item, $value);
            $errors += $valueErrors;
            // Answering a yes/no or matrix question resolves the item
            if (!$valueErrors && !isset($data['status']) && $item->input_type !== 'tick') {
                $status = ($valueColumns['value'] ?? $valueColumns['value_json'] ?? null) === null
                    ? JobCardChecklistItem::STATUS_PENDING
                    : JobCardChecklistItem::STATUS_DONE;
            }
        }

        if ($errors) {
            throw new JobSheetException('The activity could not be updated.', $errors);
        }

        DB::transaction(function () use ($item, $sheet, $status, $needsAttention, $remarks, $valueColumns, $user) {
            $resolved = in_array($status, [JobCardChecklistItem::STATUS_DONE, JobCardChecklistItem::STATUS_NOT_APPLICABLE], true);
            $statusChanged = $status !== $item->status;

            $item->fill([
                'status' => $status,
                'needs_attention' => $needsAttention,
                'remarks' => $remarks,
                'is_completed' => $resolved,
                ...$valueColumns,
            ]);

            if ($statusChanged) {
                $item->completed_by = $status === JobCardChecklistItem::STATUS_PENDING ? null : $user->id;
                $item->completed_at = $status === JobCardChecklistItem::STATUS_PENDING ? null : now();
            }

            $item->save();

            $this->syncObservation($item, $sheet, $user);

            if ($sheet->status === JobCardChecklist::STATUS_PENDING) {
                $sheet->update(['status' => JobCardChecklist::STATUS_IN_PROGRESS, 'started_at' => now()]);
            }
        });

        return $item->fresh(['media', 'completedBy']);
    }

    /**
     * Add an ad-hoc row ("Others", extra panels / parts) to a sheet.
     */
    public function addItem(JobCardChecklist $sheet, array $data, User $user): JobCardChecklistItem
    {
        $this->assertEditable($sheet, $user);

        $inputType = $data['input_type'] ?? $sheet->items()->value('input_type') ?? 'tick';
        $last = $sheet->items()->reorder()->orderByDesc('sort_order')->first();

        $item = $sheet->items()->create([
            'status' => JobCardChecklistItem::STATUS_PENDING,
            'section_name' => $data['section_name'] ?? $last?->section_name ?? 'Others',
            'item_name' => $data['item_name'],
            'input_type' => $inputType,
            'media_requirement' => ChecklistItem::MEDIA_NONE,
            'min_media_count' => 1,
            'na_allowed' => true,
            'is_required' => false,
            'sort_order' => ($last?->sort_order ?? 0) + 1,
            'is_completed' => false,
        ]);

        if ($sheet->status === JobCardChecklist::STATUS_PENDING) {
            $sheet->update(['status' => JobCardChecklist::STATUS_IN_PROGRESS, 'started_at' => now()]);
        }

        return $item->fresh(['media']);
    }

    /**
     * Attach a photo or video to an activity.
     *
     * @throws JobSheetException
     */
    public function attachMedia(
        JobCardChecklistItem $item,
        UploadedFile $file,
        User $user,
        ?UploadedFile $poster = null,
        array $meta = [],
    ): JobCardMedia {
        $sheet = $item->jobCardChecklist;
        $this->assertEditable($sheet, $user);

        $mediaType = str_starts_with((string) $file->getMimeType(), 'video/') ? JobCardMedia::TYPE_VIDEO : JobCardMedia::TYPE_PHOTO;

        if (!$this->rules->acceptsMediaType($item, $mediaType)) {
            $wanted = $item->media_requirement === ChecklistItem::MEDIA_VIDEO ? 'a video' : 'a photo';
            throw new JobSheetException("This activity needs {$wanted}.", ['file' => ["This activity needs {$wanted}."]]);
        }

        $jobCard = $sheet->jobCard;
        $stored = $this->files->uploadJobSheetMedia($file, $jobCard->id, "sheets/{$item->id}", $poster);

        $media = JobCardMedia::create([
            'job_card_id' => $jobCard->id,
            'attachable_type' => 'sheet_item',
            'attachable_id' => $item->id,
            'media_type' => $mediaType,
            'disk' => $stored['disk'],
            'file_path' => $stored['path'],
            'thumbnail_path' => $stored['thumbnail_path'],
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'duration_seconds' => $meta['duration_seconds'] ?? null,
            'width' => $stored['width'],
            'height' => $stored['height'],
            'description' => $meta['description'] ?? "{$item->item_name} — {$jobCard->job_number}",
            'uploaded_by' => $user->id,
            'captured_at' => $meta['captured_at'] ?? now(),
        ]);

        if ($sheet->status === JobCardChecklist::STATUS_PENDING) {
            $sheet->update(['status' => JobCardChecklist::STATUS_IN_PROGRESS, 'started_at' => now()]);
        }

        return $media;
    }

    /**
     * Remove a media file. A done activity that no longer has enough media goes back to pending.
     *
     * @return bool whether the activity was reverted to pending
     */
    public function deleteMedia(JobCardMedia $media, JobCardChecklistItem $item, User $user): bool
    {
        $this->assertEditable($item->jobCardChecklist, $user);

        $canDeleteAny = $user->hasPermission('workshop.job-sheets.delete-any-media');
        if ($media->uploaded_by !== $user->id && !$canDeleteAny) {
            throw new JobSheetException('You can only delete media you uploaded.', [], 403);
        }

        $media->delete();
        $item->load('media');

        if ($item->status === JobCardChecklistItem::STATUS_DONE && !$this->rules->mediaSatisfied($item)) {
            $item->update([
                'status' => JobCardChecklistItem::STATUS_PENDING,
                'is_completed' => false,
                'completed_by' => null,
                'completed_at' => null,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Submit a sheet. Unresolved items block it unless a manager overrides with a note.
     *
     * @throws JobSheetException
     */
    public function submit(JobCardChecklist $sheet, User $user, bool $override = false, ?string $notes = null): JobCardChecklist
    {
        $this->assertEditable($sheet, $user);
        $sheet->load('items.media');

        $problems = $this->rules->sheetProblems($sheet, $sheet->completionRule());
        $meta = null;

        if ($problems) {
            if (!$override) {
                throw new JobSheetException('The sheet has unfinished activities.', ['items' => $problems]);
            }
            if (!$user->hasPermission('workshop.job-cards.override-guards')) {
                throw new JobSheetException('Only a manager can submit a sheet with unfinished activities.', ['items' => $problems], 403);
            }
            if (!$this->nullIfBlank($notes)) {
                throw new JobSheetException('A note is required to override.', ['notes' => ['A note is required to override.']]);
            }
            $meta = ['override' => true, 'notes' => $notes, 'by' => $user->id, 'at' => now()->toIso8601String(), 'items' => $problems];
        }

        $sheet->update([
            'status' => JobCardChecklist::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by' => $user->id,
            'started_at' => $sheet->started_at ?? now(),
            'override_meta' => $meta,
        ]);

        return $sheet;
    }

    public function reopen(JobCardChecklist $sheet, User $user, string $notes): JobCardChecklist
    {
        if ($sheet->jobCard->isClosed()) {
            throw new JobSheetException('This job card is closed.');
        }

        $sheet->update([
            'status' => JobCardChecklist::STATUS_IN_PROGRESS,
            'completed_at' => null,
            'completed_by' => null,
            'reopened_at' => now(),
            'reopened_by' => $user->id,
            'reopen_notes' => $notes,
            'override_meta' => null,
        ]);

        return $sheet;
    }

    /**
     * Who may edit a sheet, and when.
     *
     * @throws JobSheetException
     */
    public function assertEditable(JobCardChecklist $sheet, User $user): void
    {
        $jobCard = $sheet->jobCard;

        if ($sheet->isCompleted()) {
            throw new JobSheetException('This sheet has been submitted. Reopen it to make changes.');
        }

        $key = array_key_exists($sheet->sheet_kind, self::EDITABLE_STATUSES) ? $sheet->sheet_kind : 'work';
        if (!in_array($jobCard->status, self::EDITABLE_STATUSES[$key], true)) {
            $label = JobCardStatus::tryFrom($jobCard->status)?->label() ?? $jobCard->status;
            throw new JobSheetException("This sheet can't be edited while the job is {$label}.");
        }

        // Technicians work only on jobs assigned to them; advisors/managers on any
        if ($this->isTechnicianOnly($user) && $jobCard->technician_id !== $user->id) {
            throw new JobSheetException('This job is assigned to another technician.', [], 403);
        }
    }

    public function isTechnicianOnly(User $user): bool
    {
        return !$user->hasAnyPermission(['workshop.job-cards.change-status', 'workshop.job-sheets.manage'])
            && ($user->hasRole('technician') || $user->hasRole('body-shop-technician'));
    }

    /**
     * Keep the ⚠ flag and its observation row in step.
     */
    protected function syncObservation(JobCardChecklistItem $item, JobCardChecklist $sheet, User $user): void
    {
        $existing = JobCardObservation::where('source_item_id', $item->id)->first();

        if (!$item->needs_attention) {
            $existing?->delete();
            return;
        }

        $text = trim("{$item->item_name}: {$item->remarks}");

        if ($existing) {
            $existing->update(['text' => $text]);
            return;
        }

        JobCardObservation::create([
            'job_card_id' => $sheet->job_card_id,
            'job_card_checklist_id' => $sheet->id,
            'source' => $sheet->sheet_kind === ChecklistTemplate::KIND_PRE_DELIVERY
                ? JobCardObservation::SOURCE_PRE_DELIVERY
                : JobCardObservation::SOURCE_ACTIVITY,
            'source_item_id' => $item->id,
            'text' => $text,
            'sort_order' => $item->sort_order,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Validate and map a submitted value to the right columns for the item's input type.
     *
     * @return array{0: array, 1: array} [columns, errors]
     */
    protected function normalizeValue(JobCardChecklistItem $item, mixed $value): array
    {
        switch ($item->input_type) {
            case 'yes_no':
                if ($value !== null && !in_array($value, ['yes', 'no'], true)) {
                    return [[], ['value' => ['Answer yes or no.']]];
                }
                return [['value' => $value], []];

            case 'repair_replace':
                $action = is_array($value) ? ($value['action'] ?? null) : $value;
                if ($action !== null && !in_array($action, ['ok', 'repair', 'replace'], true)) {
                    return [[], ['value' => ['Choose OK, Repair or Replace.']]];
                }
                return [['value_json' => $action === null ? null : ['action' => $action]], []];

            case 'panel_matrix':
                if ($value === null) {
                    return [['value_json' => null], []];
                }
                if (!is_array($value)) {
                    return [[], ['value' => ['Invalid panel selection.']]];
                }
                $flags = [];
                foreach (['repair', 'replace', 'partial_paint', 'full_paint'] as $key) {
                    $flags[$key] = (bool) ($value[$key] ?? false);
                }
                return [['value_json' => in_array(true, $flags, true) ? $flags : null], []];

            default:
                return [['value' => $value === null ? null : (string) $value], []];
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;
        return $value === '' ? null : $value;
    }
}
