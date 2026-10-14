<?php

namespace App\Modules\Workshop\Http\Resources;

use App\Modules\Workshop\Services\JobSheetRuleEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobSheetItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rules = app(JobSheetRuleEngine::class);
        $mediaLoaded = $this->relationLoaded('media');

        return [
            'id' => $this->id,
            'section_name' => $this->section_name,
            'item_name' => $this->item_name,
            'input_type' => $this->input_type,
            'status' => $this->status,
            'needs_attention' => $this->needs_attention,
            'remarks' => $this->remarks,
            'value' => $this->value_json ?? $this->value,
            'media_requirement' => $this->media_requirement,
            'min_media_count' => $this->min_media_count,
            'na_allowed' => $this->na_allowed,
            'expected_answer' => $this->expected_answer,
            'is_required' => $this->is_required,
            'is_adhoc' => $this->checklist_item_id === null,
            'media_shortfall' => $mediaLoaded ? $rules->mediaShortfall($this->resource) : null,
            'media' => JobCardMediaResource::collection($this->whenLoaded('media')),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $this->completedBy?->only(['id', 'name'])),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
