<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\ChecklistItem;
use App\Modules\Workshop\Models\ChecklistSection;
use App\Modules\Workshop\Models\ChecklistTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Job-sheet masters: templates, sections and activities with their media / N/A rules.
 * Changes apply to job cards created afterwards (job sheets snapshot the rules).
 */
class JobSheetMasterController extends ApiController
{
    private const KINDS = [
        ChecklistTemplate::KIND_SERVICE_ACTIVITIES,
        ChecklistTemplate::KIND_AC_SERVICE,
        ChecklistTemplate::KIND_GENERAL_REPAIR,
        ChecklistTemplate::KIND_BODY_SHOP,
        ChecklistTemplate::KIND_OBSERVATION_REPORT,
        ChecklistTemplate::KIND_PRE_DELIVERY,
    ];

    public function index(Request $request): JsonResponse
    {
        $templates = ChecklistTemplate::query()
            ->when(!$request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->with('packages:id,name,slug')
            ->withCount('items')
            ->orderBy('sheet_kind')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success($templates);
    }

    public function show(ChecklistTemplate $template): JsonResponse
    {
        return $this->success($template->load(['packages:id,name,slug', 'sections.items']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->templateRules());

        $template = DB::transaction(function () use ($data) {
            $template = ChecklistTemplate::create([
                ...collect($data)->except('package_ids')->all(),
                'slug' => $this->uniqueSlug($data['name']),
                'type' => ($data['sheet_kind'] ?? null) === ChecklistTemplate::KIND_PRE_DELIVERY ? 'qc' : 'during_service',
            ]);
            $template->packages()->sync($data['package_ids'] ?? []);
            return $template;
        });

        return $this->created($template->load('packages:id,name,slug'), 'Job sheet created');
    }

    public function update(Request $request, ChecklistTemplate $template): JsonResponse
    {
        $data = $request->validate($this->templateRules(false));

        DB::transaction(function () use ($template, $data) {
            $template->update(collect($data)->except('package_ids')->all());
            if (array_key_exists('package_ids', $data)) {
                $template->packages()->sync($data['package_ids'] ?? []);
            }
        });

        return $this->success($template->fresh('packages:id,name,slug'), 'Job sheet updated');
    }

    public function storeSection(Request $request, ChecklistTemplate $template): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255']);

        $section = $template->sections()->create([
            'name' => $data['name'],
            'sort_order' => (int) $template->sections()->max('sort_order') + 1,
        ]);

        return $this->created($section, 'Section added');
    }

    public function updateSection(Request $request, ChecklistTemplate $template, ChecklistSection $section): JsonResponse
    {
        $section->update($request->validate([
            'name' => 'sometimes|required|string|max:255',
            'sort_order' => 'sometimes|integer|min:0',
        ]));

        return $this->success($section, 'Section updated');
    }

    public function destroySection(ChecklistTemplate $template, ChecklistSection $section): JsonResponse
    {
        if ($section->items()->exists()) {
            return $this->error('Remove or move the activities in this section first.', 422);
        }

        $section->delete();

        return $this->success(null, 'Section removed');
    }

    public function storeItem(Request $request, ChecklistSection $section): JsonResponse
    {
        $data = $request->validate($this->itemRules(true));

        $item = $section->items()->create([
            ...$data,
            'input_type' => $data['input_type'] ?? 'tick',
            'requires_photo' => ($data['media_requirement'] ?? 'none') !== ChecklistItem::MEDIA_NONE,
            'sort_order' => (int) $section->items()->max('sort_order') + 1,
            'rules_customized_at' => now(),
        ]);

        return $this->created($item, 'Activity added');
    }

    public function updateItem(Request $request, ChecklistSection $section, ChecklistItem $item): JsonResponse
    {
        $data = $request->validate($this->itemRules(false));

        $ruleFields = ['media_requirement', 'min_media_count', 'na_allowed', 'workshop_only', 'is_required', 'expected_answer'];
        if (array_intersect(array_keys($data), $ruleFields)) {
            // Re-seeding the masters will no longer overwrite these rules
            $data['rules_customized_at'] = now();
        }
        if (isset($data['media_requirement'])) {
            $data['requires_photo'] = $data['media_requirement'] !== ChecklistItem::MEDIA_NONE;
        }

        $item->update($data);

        return $this->success($item, 'Activity updated');
    }

    public function reorderItems(Request $request, ChecklistSection $section): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => ['integer', Rule::exists('checklist_items', 'id')->where('section_id', $section->id)],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $position => $id) {
                ChecklistItem::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return $this->success($section->items()->get(), 'Order saved');
    }

    private function templateRules(bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes|required';

        return [
            'name' => "{$req}|string|max:255",
            'sheet_kind' => [$creating ? 'required' : 'sometimes', Rule::in(self::KINDS)],
            'service_type' => 'nullable|string|max:30',
            'applies_to_all' => 'sometimes|boolean',
            'filled_by' => ['sometimes', Rule::in(['technician', 'advisor'])],
            'has_issues_tab' => 'sometimes|boolean',
            'has_observations' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'package_ids' => 'sometimes|array',
            'package_ids.*' => 'integer|exists:service_packages,id',
        ];
    }

    private function itemRules(bool $creating): array
    {
        return [
            'item_name' => ($creating ? 'required' : 'sometimes|required') . '|string|max:255',
            'description' => 'nullable|string|max:1000',
            'input_type' => ['sometimes', Rule::in(ChecklistItem::INPUT_TYPES)],
            'media_requirement' => ['sometimes', Rule::in(ChecklistItem::MEDIA_REQUIREMENTS)],
            'min_media_count' => 'sometimes|integer|min:1|max:5',
            'na_allowed' => 'sometimes|boolean',
            'workshop_only' => 'sometimes|boolean',
            'is_required' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'expected_answer' => ['nullable', Rule::in(['yes', 'no'])],
            'applies_to_package_ids' => 'nullable|array',
            'applies_to_package_ids.*' => 'integer|exists:service_packages,id',
            'section_id' => 'sometimes|integer|exists:checklist_sections,id',
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $n = 2;
        while (ChecklistTemplate::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }
        return $slug;
    }
}
