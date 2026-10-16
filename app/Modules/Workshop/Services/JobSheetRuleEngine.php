<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardMedia;

/**
 * Pure checks for job-sheet completeness, shared by the job-sheet endpoints and
 * the status-transition guards. Expects items with their media eager-loaded.
 */
class JobSheetRuleEngine
{
    public const COMPLETION_ALL_RESOLVED = 'all_resolved';
    public const COMPLETION_ALL_ANSWERED = 'all_answered';
    public const COMPLETION_OPTIONAL = 'optional';

    /**
     * Whether the item's media satisfies its requirement.
     */
    public function mediaSatisfied(JobCardChecklistItem $item): bool
    {
        return $this->mediaShortfall($item) === null;
    }

    /**
     * Human-readable description of missing media, or null when satisfied.
     */
    public function mediaShortfall(JobCardChecklistItem $item): ?string
    {
        // Not applicable to this car: nothing to photograph
        if ($item->status === JobCardChecklistItem::STATUS_NOT_APPLICABLE) {
            return null;
        }

        $required = max(1, (int) $item->min_media_count);
        $media = $item->media;

        $photos = $media->where('media_type', JobCardMedia::TYPE_PHOTO)->count();
        $videos = $media->where('media_type', JobCardMedia::TYPE_VIDEO)->count();

        return match ($item->media_requirement) {
            ChecklistItem::MEDIA_PHOTO => $photos >= $required ? null : $this->plural($required, 'photo') . ' required',
            ChecklistItem::MEDIA_VIDEO => $videos >= $required ? null : $this->plural($required, 'video') . ' required',
            ChecklistItem::MEDIA_PHOTO_OR_VIDEO => ($photos + $videos) >= $required
                ? null
                : $this->plural($required, 'photo or video', 'photos or videos') . ' required',
            ChecklistItem::MEDIA_PHOTO_AND_VIDEO => $photos >= $required && $videos >= $required
                ? null
                : implode(' and ', array_filter([
                    $photos < $required ? $this->plural($required, 'photo') : null,
                    $videos < $required ? $this->plural($required, 'video') : null,
                ])) . ' required',
            default => null,
        };
    }

    /**
     * Whether a media type may be attached to the item.
     */
    public function acceptsMediaType(JobCardChecklistItem $item, string $mediaType): bool
    {
        return match ($item->media_requirement) {
            ChecklistItem::MEDIA_PHOTO => $mediaType === JobCardMedia::TYPE_PHOTO,
            ChecklistItem::MEDIA_VIDEO => $mediaType === JobCardMedia::TYPE_VIDEO,
            // Optional evidence on non-media activities is allowed in either form
            default => true,
        };
    }

    /**
     * Problems that stop a sheet from being submitted.
     *
     * @return array<int, array{item_id: int, item_name: ?string, section_name: ?string, code: string, message: string}>
     */
    public function sheetProblems(JobCardChecklist $sheet, string $completionRule): array
    {
        // Rows answered with a choice (body-shop panels, repair/replace) still need the photos/videos
        // the master asks for, whatever the sheet's completion rule
        $problems = [];
        foreach ($sheet->items as $item) {
            if (in_array($item->input_type, ['tick', 'yes_no'], true) || !$this->isAnswered($item)) {
                continue;
            }
            if ($shortfall = $this->mediaShortfall($item)) {
                $problems[] = [
                    'item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'section_name' => $item->section_name,
                    'code' => 'media_missing',
                    'message' => ucfirst($shortfall),
                ];
            }
        }

        if ($completionRule === self::COMPLETION_OPTIONAL) {
            return $problems;
        }

        $flagged = array_column($problems, 'item_id');
        // Flagged activities whose Additional Issues line is closed were handled there
        $handled = $this->closedLineItemIds($sheet->items);

        foreach ($sheet->items as $item) {
            if (in_array($item->id, $flagged, true) || $handled->contains($item->id)) {
                continue;
            }
            $problem = $completionRule === self::COMPLETION_ALL_ANSWERED
                ? $this->answerProblem($item)
                : $this->activityProblem($item);

            if ($problem) {
                $problems[] = [
                    'item_id' => $item->id,
                    'item_name' => $item->item_name,
                    'section_name' => $item->section_name,
                    ...$problem,
                ];
            }
        }

        return $problems;
    }

    /**
     * Rule for activity sheets: each required activity done (with media) or N/A.
     */
    public function activityProblem(JobCardChecklistItem $item): ?array
    {
        if ($item->status === JobCardChecklistItem::STATUS_NOT_DONE) {
            return ['code' => 'not_done', 'message' => 'Marked not done'];
        }

        if ($item->status === JobCardChecklistItem::STATUS_DONE) {
            $shortfall = $this->mediaShortfall($item);
            return $shortfall ? ['code' => 'media_missing', 'message' => ucfirst($shortfall)] : null;
        }

        if ($item->status === JobCardChecklistItem::STATUS_PENDING && $item->is_required) {
            return ['code' => 'pending', 'message' => 'Not done yet'];
        }

        return null;
    }

    /**
     * A choice-type row counts as answered once something is selected (or it is marked done).
     */
    public function isAnswered(JobCardChecklistItem $item): bool
    {
        if ($item->status === JobCardChecklistItem::STATUS_DONE) {
            return true;
        }

        // Panel / repair-replace choices are stored as flags in value_json
        if (is_array($item->value_json) && collect($item->value_json)->contains(fn ($v) => !empty($v))) {
            return true;
        }

        return $item->value !== null && $item->value !== '';
    }

    /**
     * Rule for yes/no checklists (pre-delivery): answered and matching the expected answer.
     */
    public function answerProblem(JobCardChecklistItem $item): ?array
    {
        if (!$item->is_required) {
            return null;
        }

        if (!in_array($item->value, ['yes', 'no'], true)) {
            return ['code' => 'unanswered', 'message' => 'Not answered'];
        }

        if ($item->expected_answer && $item->value !== $item->expected_answer) {
            return ['code' => 'failed_check', 'message' => 'Answered "' . $item->value . '" — needs fixing'];
        }

        // QC spot check marked but its photo/video was never taken
        if ($item->qc_evidence_required && $item->media->isEmpty()) {
            return ['code' => 'qc_evidence_missing', 'message' => 'QC spot check: photo/video evidence needed'];
        }

        return null;
    }

    /**
     * Progress summary for the UI.
     */
    public function progress(JobCardChecklist $sheet): array
    {
        $items = $sheet->items;
        $handled = $this->closedLineItemIds($items);
        $resolved = $items->filter(fn ($i) => $handled->contains($i->id) || ($i->isResolved() && $this->mediaSatisfied($i)))->count();

        return [
            'total' => $items->count(),
            'resolved' => $resolved,
            'needs_media' => $items->filter(fn ($i) => $i->status === JobCardChecklistItem::STATUS_DONE && !$this->mediaSatisfied($i))->count(),
            'not_done' => $items->where('status', JobCardChecklistItem::STATUS_NOT_DONE)->count(),
            // A ⚠ flag counts while its Additional Issues line is still open
            'needs_attention' => $this->openFlags($items),
        ];
    }

    private function openFlags($items): int
    {
        $flagged = $items->where('needs_attention', true)->pluck('id');

        return $flagged->diff($this->closedLineItemIds($items))->count();
    }

    /**
     * Flagged activities whose Additional Issues line is done, deferred, or declined by the customer.
     */
    public function closedLineItemIds($items): \Illuminate\Support\Collection
    {
        $flagged = $items->where('needs_attention', true)->pluck('id');
        if ($flagged->isEmpty()) {
            return collect();
        }

        $rules = app(IssueLineRules::class);

        return JobCardIssue::whereIn('source_item_id', $flagged)
            ->with(IssueLineRules::RELATIONS)
            ->get()
            ->filter(fn (JobCardIssue $line) => in_array($line->status, ['resolved', 'deferred'], true) || $rules->declinedByCustomer($line))
            ->pluck('source_item_id')
            ->values();
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} " . ($plural ?? $singular . 's');
    }
}
