<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobCardMedia;
use Illuminate\Support\Collection;

/**
 * The customer's photo/video gallery, section-wise:
 *  - the car at arrival (intake inventory);
 *  - each job sheet, by its sections, each photo labelled with its activity;
 *  - additional work, per line: before (the fault) and after (new part fitted).
 * Final-QC (pre-delivery) evidence is internal and never shown; an advisor can hide
 * any other photo/video. Also builds the clubbed "new photos/videos" notice.
 */
class CustomerGalleryService
{
    /**
     * @return array<int, array{key: string, title: string, count: int, groups: array}>
     */
    public function build(JobCard $jobCard, ?int $afterMediaId = null): array
    {
        $media = JobCardMedia::where('job_card_id', $jobCard->id)
            ->where('customer_visible', true)
            ->when($afterMediaId, fn ($q) => $q->where('id', '>', $afterMediaId))
            ->orderBy('id')
            ->get();

        if ($media->isEmpty()) {
            return [];
        }

        $byType = $media->groupBy('attachable_type');
        $areas = [];

        if ($intake = $byType->get('job_card')) {
            $areas[] = $this->area('intake', 'Your car at arrival', [['title' => null, 'items' => $this->items($intake)]]);
        }

        if ($sheetMedia = $byType->get('sheet_item')) {
            $items = JobCardChecklistItem::with('jobCardChecklist:id,name,sheet_kind')
                ->whereIn('id', $sheetMedia->pluck('attachable_id')->unique())
                ->get()
                ->keyBy('id');

            $bySheet = $sheetMedia
                ->filter(fn (JobCardMedia $m) => ($sheet = $items[$m->attachable_id]?->jobCardChecklist)
                    && $sheet->sheet_kind !== ChecklistTemplate::KIND_PRE_DELIVERY)
                ->groupBy(fn (JobCardMedia $m) => $items[$m->attachable_id]->job_card_checklist_id);

            foreach ($bySheet as $sheetId => $list) {
                $sheet = $items[$list->first()->attachable_id]->jobCardChecklist;
                $groups = $list
                    ->groupBy(fn (JobCardMedia $m) => $items[$m->attachable_id]->section_name ?: 'Work done')
                    ->map(fn (Collection $g, string $section) => [
                        'title' => $section,
                        'items' => $this->items($g, fn (JobCardMedia $m) => $items[$m->attachable_id]->item_name),
                    ])->values()->all();

                $areas[] = $this->area("sheet-{$sheetId}", self::sheetTitle($sheet->name), $groups);
            }
        }

        $lineMedia = collect([...($byType->get('job_card_issue') ?? []), ...($byType->get('job_card_additional_job') ?? [])]);
        if ($lineMedia->isNotEmpty()) {
            $issues = JobCardIssue::whereIn('id', $lineMedia->where('attachable_type', 'job_card_issue')->pluck('attachable_id'))->pluck('description', 'id');
            $jobs = JobCardAdditionalJob::whereIn('id', $lineMedia->where('attachable_type', 'job_card_additional_job')->pluck('attachable_id'))->pluck('description', 'id');

            $groups = $lineMedia
                ->groupBy(fn (JobCardMedia $m) => "{$m->attachable_type}:{$m->attachable_id}")
                ->map(function (Collection $g) use ($issues, $jobs) {
                    $first = $g->first();
                    $title = $first->attachable_type === 'job_card_issue' ? $issues[$first->attachable_id] ?? null : $jobs[$first->attachable_id] ?? null;

                    return [
                        'title' => $title ?? 'Additional work',
                        'items' => $this->items($g, fn (JobCardMedia $m) => $m->tag === JobCardMedia::TAG_FITTED_PART ? 'After: new part fitted' : 'Before'),
                    ];
                })->values()->all();

            $areas[] = $this->area('additional', 'Additional work', $groups);
        }

        // Drop / home-visit closing photos (front, rear, left, right…)
        if ($handover = $byType->get('job_trip')) {
            $areas[] = $this->area('handover', 'Your car at delivery', [[
                'title' => null,
                'items' => $this->items($handover, fn (JobCardMedia $m) => $m->tag ? ucfirst($m->tag) : null),
            ]]);
        }

        return $areas;
    }

    /**
     * One line for WhatsApp about photos/videos added since the customer was last told,
     * clubbed per area so long plans don't list every section. Null when nothing is new.
     */
    public function noticeSince(JobCard $jobCard): ?string
    {
        $areas = $this->build($jobCard, $jobCard->gallery_notified_media_id);

        if (!$areas) {
            return null;
        }

        $parts = array_map(function (array $area) {
            $sections = count($area['groups']);
            $what = $area['key'] !== 'intake' && $area['key'] !== 'additional' && $sections > 1
                ? "{$area['title']} ({$sections} sections)"
                : $area['title'];

            return "{$what}: {$area['count']}";
        }, $areas);

        return __('customer_messages.gallery_line', ['sections' => implode(', ', $parts)]);
    }

    public function markNotified(JobCard $jobCard): void
    {
        $last = JobCardMedia::where('job_card_id', $jobCard->id)->where('customer_visible', true)->max('id');
        $jobCard->forceFill(['gallery_notified_media_id' => $last])->saveQuietly();
    }

    /**
     * "EA 360 Service Checklist" / "PRO Service (45 Points) — Technician Job Sheet" → customer wording.
     */
    public static function sheetTitle(string $name): string
    {
        $name = preg_replace('/\s+—\s+.*$/u', '', $name);

        return trim(preg_replace('/\s*(Checklist|Job Card|Job Sheet)$/i', '', $name)) ?: 'Service work';
    }

    private function area(string $key, string $title, array $groups): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'count' => array_sum(array_map(fn ($g) => count($g['items']), $groups)),
            'groups' => $groups,
        ];
    }

    private function items(Collection $media, ?callable $caption = null): array
    {
        return $media->map(fn (JobCardMedia $m) => [
            'id' => $m->id,
            'media_type' => $m->media_type,
            'caption' => $caption ? $caption($m) : null,
            'url' => $m->url(),
            'thumbnail_url' => $m->thumbnail_path ? $m->url(true) : null,
            'captured_at' => ($m->captured_at ?? $m->created_at)?->toIso8601String(),
        ])->values()->all();
    }
}
