<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Services\FileStorageService;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\BookingSlot;
use App\Modules\Crm\Models\Campaign;
use App\Modules\Crm\Models\CampaignSpend;
use App\Modules\Crm\Models\Creative;
use App\Modules\Crm\Models\Journey;
use App\Modules\Crm\Models\JourneyStep;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Workshop\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings › CRM: lead sources (with their webhook links), campaigns and ad spend, lost
 * reasons, creatives, journeys, booking slots, SLA / sending / referral settings.
 */
class CrmMasterController extends ApiController
{
    public const SETTINGS = [
        'crm.sla_minutes' => 'integer|min:1|max:1440',
        'crm.work_start' => 'date_format:H:i',
        'crm.work_end' => 'date_format:H:i',
        'crm.quiet_start' => 'date_format:H:i',
        'crm.quiet_end' => 'date_format:H:i',
        'crm.frequency_cap_days' => 'integer|min:0|max:60',
        'crm.no_show_after_hours' => 'integer|min:1|max:48',
        'crm.win_back_months' => 'integer|min:0|max:36',
        'crm.referral_reward' => 'numeric|min:0|max:100000',
        'crm.google_review_url' => 'nullable|url|max:500',
        'crm.offer_text' => 'nullable|string|max:200',
        'crm.booking_days_ahead' => 'integer|min:1|max:60',
        'crm.link_valid_days' => 'integer|min:7|max:365',
        'crm.messages.booking_confirmed' => 'string|max:1000',
        'crm.messages.booking_reminder' => 'string|max:1000',
        'crm.messages.plan_quote' => 'string|max:1500',
    ];

    public function index(Request $request): JsonResponse
    {
        $settings = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            $settings[$key] = WorkshopSettings::get($key);
        }
        $canSee = $request->user()->hasPermission('crm.settings.manage');

        return $this->success([
            'sources' => LeadSource::orderBy('sort_order')->get()->map(fn (LeadSource $s) => [
                'id' => $s->id, 'name' => $s->name, 'slug' => $s->slug, 'is_paid' => $s->is_paid, 'is_active' => $s->is_active,
                'webhook_url' => $canSee ? $s->webhookUrl() : null,
            ]),
            'campaigns' => Campaign::with(['source:id,name', 'spends'])->latest('id')->get()->map(fn (Campaign $c) => [
                'id' => $c->id, 'name' => $c->name, 'external_ref' => $c->external_ref, 'lead_source_id' => $c->lead_source_id, 'source' => $c->source?->name,
                'is_active' => $c->is_active, 'spends' => $c->spends->sortByDesc('period')->map(fn ($s) => ['period' => $s->period, 'amount' => $s->amount])->values(),
            ]),
            'lost_reasons' => LostReason::orderBy('sort_order')->get(),
            'creatives' => Creative::with('package:id,name')->latest('id')->get()->map(fn (Creative $c) => $this->presentCreative($c)),
            'journeys' => Journey::with('steps.creative:id,title')->orderBy('id')->get()->map(fn (Journey $j) => [
                'id' => $j->id, 'name' => $j->name, 'trigger' => $j->trigger, 'branch_id' => $j->branch_id, 'is_active' => $j->is_active, 'description' => $j->description,
                'steps' => $j->steps->map(fn (JourneyStep $s) => [
                    'id' => $s->id, 'day_offset' => $s->day_offset, 'send_at' => substr((string) $s->send_at, 0, 5), 'action' => $s->action,
                    'creative_id' => $s->creative_id, 'creative' => $s->creative?->title, 'task_note' => $s->task_note,
                ])->values(),
            ]),
            'slots' => BookingSlot::orderBy('branch_id')->orderBy('weekday')->orderBy('start_time')->get()->map(fn ($s) => [
                'id' => $s->id, 'branch_id' => $s->branch_id, 'weekday' => $s->weekday, 'start_time' => substr($s->start_time, 0, 5),
                'end_time' => substr($s->end_time, 0, 5), 'capacity' => $s->capacity, 'is_active' => $s->is_active,
            ]),
            'triggers' => Journey::TRIGGERS,
            'due_triggers' => Journey::DUE_DATE_TRIGGERS,
            'actions' => JourneyStep::ACTIONS,
            'plans' => ServicePackage::orderBy('sort_order')->get(['id', 'name']),
            'settings' => $settings,
        ]);
    }

    // ---------------------------------------------------------------- sources

    public function saveSource(Request $request, ?LeadSource $source = null): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ]);
        $source ??= new LeadSource(['slug' => Str::slug($data['name'], '_') . '_' . Str::lower(Str::random(4)), 'webhook_key' => Str::random(32), 'sort_order' => 99]);
        $source->fill($data)->save();

        return $this->success(['id' => $source->id, 'webhook_url' => $source->webhookUrl()], 'Source saved');
    }

    public function newWebhookKey(LeadSource $source): JsonResponse
    {
        $source->update(['webhook_key' => Str::random(32)]);

        return $this->success(['webhook_url' => $source->webhookUrl()], 'New link made — update it in the ad platform / connector');
    }

    // -------------------------------------------------------------- campaigns

    public function saveCampaign(Request $request, ?Campaign $campaign = null): JsonResponse
    {
        $data = $request->validate([
            'lead_source_id' => 'required|integer|exists:lead_sources,id',
            'name' => 'required|string|max:150',
            'external_ref' => 'nullable|string|max:150',
            'is_active' => 'boolean',
        ]);
        $campaign ??= new Campaign();
        $campaign->fill($data)->save();

        return $this->success(['id' => $campaign->id], 'Campaign saved');
    }

    public function saveSpend(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate(['period' => 'required|string|size:7', 'amount' => 'required|numeric|min:0|max:100000000']);
        CampaignSpend::updateOrCreate(['campaign_id' => $campaign->id, 'period' => $data['period']], ['amount' => $data['amount'], 'created_by' => $request->user()->id]);

        return $this->success(null, 'Spend saved');
    }

    // ----------------------------------------------------------- lost reasons

    public function saveLostReason(Request $request, ?LostReason $reason = null): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'nurture' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'nullable|integer']);
        $reason ??= new LostReason();
        $reason->fill($data)->save();

        return $this->success($reason->fresh(), 'Saved');
    }

    // -------------------------------------------------------------- creatives

    public function saveCreative(Request $request, FileStorageService $files, ?Creative $creative = null): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'caption' => 'required|string|max:2000',
            'package_id' => 'nullable|integer|exists:service_packages,id',
            'wa_template' => 'nullable|string|max:100',
            'wa_language' => 'nullable|string|max:10',
            'is_active' => 'boolean',
            'remove_file' => 'boolean',
            'file' => 'nullable|file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,application/pdf|max:' . max(16384, (int) config('workshop.job_sheets.max_video_kb')),
        ]);

        $creative ??= new Creative(['kind' => 'text']);
        $creative->fill(collect($data)->except(['file', 'remove_file'])->all());

        if ($request->hasFile('file')) {
            $stored = $files->uploadPrivateMedia($request->file('file'), 'creatives');
            $mime = (string) $stored['mime'];
            $creative->fill([
                'file_disk' => $stored['disk'],
                'file_path' => $stored['path'],
                'mime' => $mime,
                'kind' => str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'pdf'),
            ]);
        } elseif (!empty($data['remove_file'])) {
            $creative->fill(['file_disk' => null, 'file_path' => null, 'mime' => null, 'kind' => 'text']);
        }
        $creative->save();

        return $this->success($this->presentCreative($creative->fresh('package')), 'Creative saved');
    }

    public function creativeFile(Creative $creative)
    {
        abort_unless($creative->file_path, 404);
        $disk = Storage::disk($creative->file_disk ?: 'job_media');
        abort_unless($disk->exists($creative->file_path), 404);

        return response()->file($disk->path($creative->file_path), ['Content-Type' => $creative->mime ?: 'application/octet-stream']);
    }

    protected function presentCreative(Creative $c): array
    {
        return [
            'id' => $c->id, 'title' => $c->title, 'kind' => $c->kind, 'caption' => $c->caption, 'package_id' => $c->package_id,
            'package' => $c->package?->name, 'wa_template' => $c->wa_template, 'wa_language' => $c->wa_language, 'is_active' => $c->is_active,
            'has_file' => $c->hasFile(), 'mime' => $c->mime,
            'file_url' => $c->hasFile() ? \Illuminate\Support\Facades\URL::temporarySignedRoute('crm.creative.file', now()->addMinutes(30), ['creative' => $c->id]) : null,
        ];
    }

    // --------------------------------------------------------------- journeys

    /**
     * A journey with all its steps (the steps are replaced).
     */
    public function saveJourney(Request $request, ?Journey $journey = null): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'trigger' => ['required', Rule::in(array_keys(Journey::TRIGGERS))],
            'branch_id' => 'nullable|integer|exists:branches,id',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:500',
            'steps' => 'required|array|min:1|max:40',
            'steps.*.day_offset' => 'required|integer|between:-365,730',
            'steps.*.send_at' => 'nullable|date_format:H:i',
            'steps.*.action' => ['required', Rule::in(array_keys(JourneyStep::ACTIONS))],
            'steps.*.creative_id' => 'nullable|integer|exists:creatives,id',
            'steps.*.task_note' => 'nullable|string|max:255',
        ]);

        foreach ($data['steps'] as $i => $step) {
            if ($step['action'] === 'message' && empty($step['creative_id'])) {
                return $this->error('Choose the creative for each message step.', 422, ["steps.{$i}.creative_id" => ['Choose a creative.']]);
            }
            if ($step['day_offset'] < 0 && !in_array($data['trigger'], Journey::DUE_DATE_TRIGGERS, true)) {
                return $this->error('Only due-date journeys can have steps before day 0.', 422, ["steps.{$i}.day_offset" => ['0 or more.']]);
            }
        }

        $journey = DB::transaction(function () use ($data, $journey) {
            $journey ??= new Journey();
            $journey->fill(collect($data)->except('steps')->all())->save();
            $journey->steps()->delete();
            foreach (array_values($data['steps']) as $i => $s) {
                $journey->steps()->create([
                    'day_offset' => $s['day_offset'],
                    'send_at' => ($s['send_at'] ?? '10:30') . ':00',
                    'action' => $s['action'],
                    'creative_id' => $s['action'] === 'message' ? $s['creative_id'] : null,
                    'task_note' => $s['task_note'] ?? null,
                    'sort_order' => $i,
                ]);
            }

            return $journey;
        });

        return $this->success(['id' => $journey->id], 'Journey saved');
    }

    // ------------------------------------------------------------------ slots

    /**
     * Replace the slots of one branch (or the shared ones, branch_id null).
     */
    public function saveSlots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'slots' => 'present|array|max:200',
            'slots.*.weekday' => 'required|integer|between:0,6',
            'slots.*.start_time' => 'required|date_format:H:i',
            'slots.*.end_time' => 'required|date_format:H:i',
            'slots.*.capacity' => 'required|integer|min:0|max:100',
            'slots.*.is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($data) {
            BookingSlot::where(fn ($q) => isset($data['branch_id']) ? $q->where('branch_id', $data['branch_id']) : $q->whereNull('branch_id'))->delete();
            foreach ($data['slots'] as $s) {
                BookingSlot::create([...$s, 'branch_id' => $data['branch_id'] ?? null, 'is_active' => $s['is_active'] ?? true]);
            }
        });

        return $this->success(null, 'Booking slots saved');
    }

    // --------------------------------------------------------------- settings

    public function saveSettings(Request $request): JsonResponse
    {
        $rules = [];
        foreach (self::SETTINGS as $key => $rule) {
            $rules[str_replace('.', '__', $key)] = "sometimes|{$rule}";
        }
        $input = [];
        foreach ($request->input('settings', []) as $key => $value) {
            $input[str_replace('.', '__', $key)] = $value;
        }
        $data = validator($input, $rules)->validate();
        foreach ($data as $key => $value) {
            WorkshopSettings::set(str_replace('__', '.', $key), $value ?? '');
        }

        return $this->index($request);
    }
}
