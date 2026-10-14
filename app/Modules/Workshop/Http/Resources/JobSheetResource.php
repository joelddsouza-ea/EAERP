<?php

namespace App\Modules\Workshop\Http\Resources;

use App\Modules\Workshop\Services\JobSheetRuleEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job card's job sheet with items grouped into sections, progress, and
 * the problems (if any) that would block submission.
 */
class JobSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rules = app(JobSheetRuleEngine::class);
        $template = $this->relationLoaded('template') ? $this->template : null;
        $problems = $this->relationLoaded('items') ? $rules->sheetProblems($this->resource, $this->completionRule()) : [];

        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'sheet_kind' => $this->sheet_kind,
            'name' => $this->name,
            'status' => $this->status,
            'completion_rule' => $this->completionRule(),
            'filled_by' => $template?->filled_by ?? 'technician',
            'has_issues_tab' => (bool) ($template?->has_issues_tab ?? false),
            'has_observations' => (bool) ($template?->has_observations ?? false),
            'progress' => $this->relationLoaded('items') ? $rules->progress($this->resource) : null,
            'submittable' => empty($problems),
            'problems' => $problems,
            'sections' => $this->whenLoaded('items', fn () => $this->items
                ->groupBy(fn ($item) => $item->section_name ?? 'Activities')
                ->map(fn ($items, $name) => [
                    'name' => $name,
                    'items' => JobSheetItemResource::collection($items->values()),
                ])
                ->values()),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->only(['id', 'name'])),
            'override' => (bool) ($this->override_meta['override'] ?? false),
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'reopen_notes' => $this->reopen_notes,
        ];
    }
}
