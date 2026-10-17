<?php

namespace Database\Seeders;

use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\ChecklistSection;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Services\JobSheetRuleEngine;
use Database\Seeders\Support\MarkerParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the job-sheet masters from database/seeders/data/job_sheets/*.php
 * (transcribed from the owner's paper job sheets).
 *
 * Safe to re-run: templates/items are matched by slug/code, and activity rules the
 * owner has edited in the masters screen (rules_customized_at set) are left alone.
 */
class JobSheetTemplatesSeeder extends Seeder
{
    /**
     * Placeholder templates from the original ChecklistTemplatesSeeder, replaced by these sheets.
     */
    private const LEGACY_SLUGS = [
        'pre-delivery-checklist',
        'ea-refresh-service-checklist',
        'ea-essentials-service-checklist',
        'ea-pro-service-checklist',
        'ea-360-service-checklist',
        'ea-elite-service-checklist',
    ];

    public function run(): void
    {
        $packageIds = ServicePackage::pluck('id', 'slug')->all();
        $starDefault = config('workshop.job_sheets.star_default_media', ChecklistItem::MEDIA_PHOTO_OR_VIDEO);

        DB::transaction(function () use ($packageIds, $starDefault) {
            foreach (glob(database_path('seeders/data/job_sheets/*.php')) as $file) {
                $this->seedSheet(require $file, $packageIds, $starDefault);
            }

            ChecklistTemplate::whereIn('slug', self::LEGACY_SLUGS)->update(['is_active' => false]);
        });

        // Remarks for observations (Customer not approved, Fix at next service, ...)
        $this->call(ObservationRemarksSeeder::class);
    }

    private function seedSheet(array $data, array $packageIds, string $starDefault): void
    {
        $kind = $data['sheet_kind'];

        $template = ChecklistTemplate::withTrashed()->updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'package_id' => null,
                'type' => $kind === ChecklistTemplate::KIND_PRE_DELIVERY ? 'qc' : 'during_service',
                'sheet_kind' => $kind,
                'service_type' => $data['service_type'] ?? null,
                'applies_to_all' => $data['applies_to_all'] ?? false,
                'completion_rule' => match ($kind) {
                    ChecklistTemplate::KIND_PRE_DELIVERY => JobSheetRuleEngine::COMPLETION_ALL_ANSWERED,
                    ChecklistTemplate::KIND_OBSERVATION_REPORT => JobSheetRuleEngine::COMPLETION_OPTIONAL,
                    default => JobSheetRuleEngine::COMPLETION_ALL_RESOLVED,
                },
                'filled_by' => $data['filled_by'] ?? 'technician',
                'has_issues_tab' => $data['has_issues_tab'] ?? false,
                'has_observations' => $data['has_observations'] ?? false,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]
        );
        if ($template->trashed()) {
            $template->restore();
        }

        $template->packages()->sync($this->packageIdsFor($data['packages'] ?? [], $packageIds, $data['slug']));

        $inputType = $data['input_type'] ?? 'tick';
        $required = $data['required'] ?? true;
        $seededCodes = [];
        $sectionOrder = 0;
        $itemIndex = 0;

        foreach ($data['sections'] as $sectionName => $items) {
            $sectionOrder++;
            $section = ChecklistSection::updateOrCreate(
                ['template_id' => $template->id, 'name' => $sectionName],
                ['sort_order' => $sectionOrder]
            );

            foreach ($items as $position => $row) {
                $itemIndex++;
                [$name, $marker] = [$row[0], $row[1] ?? ''];
                $code = $data['slug'] . '.' . str_pad((string) $itemIndex, 3, '0', STR_PAD_LEFT);
                $seededCodes[] = $code;

                $parsed = MarkerParser::parse($marker, $starDefault);
                if ($parsed['unknown']) {
                    $this->command?->warn("Unrecognised marker \"{$marker}\" on {$code} ({$name}); treated as no media.");
                }

                $rules = [
                    'media_requirement' => $parsed['media_requirement'],
                    'min_media_count' => 1,
                    'na_allowed' => ($row['na'] ?? false) || $parsed['na_allowed'],
                    'is_required' => $required,
                    'expected_answer' => $row['expect'] ?? null,
                    'workshop_only' => ChecklistItem::looksWorkshopOnly($name),
                ];

                $item = ChecklistItem::where('code', $code)->first();
                $attributes = [
                    'section_id' => $section->id,
                    'item_name' => $name,
                    'input_type' => $inputType,
                    'source_marker' => $marker !== '' ? $marker : null,
                    'applies_to_package_ids' => isset($row['packages'])
                        ? $this->packageIdsFor($row['packages'], $packageIds, $code)
                        : null,
                    'requires_photo' => $rules['media_requirement'] !== ChecklistItem::MEDIA_NONE,
                    'sort_order' => $position + 1,
                    'is_active' => true,
                ];

                if ($item) {
                    // Keep the owner's edits to media / N/A rules
                    $item->update($item->rules_customized_at ? $attributes : $attributes + $rules);
                } else {
                    ChecklistItem::create(['code' => $code] + $attributes + $rules);
                }
            }
        }

        // Seeded activities that were removed from the data file are deactivated, never deleted
        ChecklistItem::whereIn('section_id', $template->sections()->pluck('id'))
            ->where('code', 'like', $data['slug'] . '.%')
            ->whereNotIn('code', $seededCodes)
            ->update(['is_active' => false]);

        $this->command?->info(sprintf('%-28s %3d activities', $data['slug'], $itemIndex));
    }

    private function packageIdsFor(array $slugs, array $packageIds, string $context): array
    {
        $ids = [];
        foreach ($slugs as $slug) {
            if (!isset($packageIds[$slug])) {
                $this->command?->warn("Unknown service package \"{$slug}\" referenced by {$context}.");
                continue;
            }
            $ids[] = $packageIds[$slug];
        }
        return $ids;
    }
}
