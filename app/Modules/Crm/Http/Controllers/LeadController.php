<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadActivity;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Services\CrmException;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends ApiController
{
    public function __construct(protected LeadService $leads) {}

    /**
     * Leads: "today" (my follow-ups and new leads to call), a status board, or a filtered list.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $user->hasPermission('crm.leads.manage');
        $view = $request->query('view', 'today');

        if ($view === 'today') {
            $rows = $this->leads->dueFollowUps($all && $request->boolean('everyone') ? null : $user->id);

            return $this->success(['view' => 'today', 'rows' => $rows->map(fn ($l) => $this->leads->present($l))->values()]);
        }

        $q = Lead::query()
            ->with(['source:id,name,slug', 'campaign:id,name', 'assignee:id,name', 'lostReason:id,name', 'package:id,name', 'category:id,name', 'customer:id,name', 'vehicle.make', 'vehicle.model', 'make', 'model'])
            ->when(!$all, fn ($q) => $q->where('assigned_to', $user->id))
            ->when(!$user->isOwner(), fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $user->getAccessibleBranchIds())))
            ->when($request->integer('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->open() : $q->where('status', $s))
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('temperature'), fn ($q, $t) => $q->where('temperature', $t))
            ->when($request->integer('source_id'), fn ($q, $id) => $q->where('lead_source_id', $id))
            ->when($request->integer('campaign_id'), fn ($q, $id) => $q->where('campaign_id', $id))
            ->when($request->integer('assigned_to'), fn ($q, $id) => $q->where('assigned_to', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', $d . ' 00:00:00'))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', $d . ' 23:59:59'))
            ->when($request->query('q'), function ($q, $t) {
                $digits = preg_replace('/\D+/', '', $t);
                $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('car_text', 'like', "%{$t}%")->orWhere('registration_no', 'like', '%' . strtoupper(str_replace(' ', '', $t)) . '%')
                    ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%")));
            });

        if ($view === 'board') {
            $rows = $q->where(fn ($w) => $w->open()->orWhere('closed_at', '>=', now()->subDays(14)))->latest('id')->limit(400)->get();

            return $this->success(['view' => 'board', 'rows' => $rows->map(fn ($l) => $this->leads->present($l))->values()]);
        }

        $page = $q->latest('id')->paginate(min(100, (int) $request->query('per_page', 50)));
        $page->setCollection($page->getCollection()->map(fn ($l) => $this->leads->present($l)));

        return $this->paginated($page);
    }

    public function options(Request $request): JsonResponse
    {
        return $this->success([
            'sources' => LeadSource::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
            'lost_reasons' => LostReason::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'nurture']),
            'plans' => ServicePackage::where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'home_service_available']),
            'categories' => VehicleCategory::orderBy('id')->get(['id', 'name']),
            'oil_types' => \App\Modules\Workshop\Models\OilType::orderBy('id')->get(['id', 'name']),
            'interests' => Lead::INTERESTS,
            'statuses' => Lead::STATUSES,
            'kinds' => Lead::KINDS,
            'outcomes' => LeadActivity::OUTCOMES,
            'executives' => User::active()->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'crm.leads.work'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['source' => 'nullable|string|max:40', 'lead_source_id' => 'nullable|integer|exists:lead_sources,id', 'note' => 'nullable|string|max:1000', 'assigned_to' => 'nullable|integer|exists:users,id']);
        if (!empty($data['assigned_to']) && !$request->user()->hasPermission('crm.leads.manage')) {
            unset($data['assigned_to']);
        }

        [$lead, $new] = $this->leads->capture($data, $request->user());

        return $this->created($this->leads->present($lead, true), $new ? 'Lead added' : 'This number already has an open lead — it is updated');
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        return $this->success($this->leads->present($lead, true));
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $data = $request->validate($this->rules(false) + ['branch_id' => 'nullable|integer|exists:branches,id']);
        if (isset($data['registration_no'])) {
            $data['registration_no'] = strtoupper(preg_replace('/\s+/', '', $data['registration_no']));
        }
        unset($data['phone']);
        $lead->update($data);

        return $this->success($this->leads->present($lead->fresh(), true), 'Saved');
    }

    public function call(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $data = $request->validate([
            'outcome' => 'required|string',
            'note' => 'nullable|string|max:1000',
            'call_back_at' => 'nullable|date',
            'lost_reason_id' => 'nullable|integer|exists:lost_reasons,id',
        ]);

        return $this->success($this->leads->present($this->leads->logCall($lead, $data, $request->user()), true), 'Call logged');
    }

    public function note(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $data = $request->validate(['body' => 'required|string|max:2000', 'next_follow_up_at' => 'nullable|date']);
        $this->leads->log($lead, 'note', null, $data['body'], $request->user());
        $lead->update(array_filter(['next_follow_up_at' => $data['next_follow_up_at'] ?? null, 'last_activity_at' => now()]));

        return $this->success($this->leads->present($lead->fresh(), true), 'Note added');
    }

    public function followUp(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $data = $request->validate(['next_follow_up_at' => 'required|date']);
        $lead->update(['next_follow_up_at' => $data['next_follow_up_at']]);

        return $this->success($this->leads->present($lead->fresh(), true), 'Follow-up set');
    }

    public function assign(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $this->leads->assign($lead, User::findOrFail($data['user_id']), $request->user());

        return $this->success($this->leads->present($lead->fresh(), true), 'Lead reassigned');
    }

    public function interested(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $this->leads->setStatus($lead, Lead::INTERESTED, $request->user());

        return $this->success($this->leads->present($lead->fresh(), true));
    }

    public function lost(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $data = $request->validate(['lost_reason_id' => 'required|integer|exists:lost_reasons,id', 'note' => 'nullable|string|max:500']);

        return $this->success($this->leads->present($this->leads->markLost($lead, (int) $data['lost_reason_id'], $data['note'] ?? null, $request->user()), true), 'Lead closed');
    }

    public function reopen(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        return $this->success($this->leads->present($this->leads->reopen($lead, $request->user()), true), 'Lead reopened');
    }

    public function optOut(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);

        return $this->success($this->leads->present($this->leads->optOut($lead, $request->user()), true), 'No more messages will go to this number');
    }

    public function quote(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizeLead($request, $lead);
        $data = $request->validate(['package_id' => 'required|integer|exists:service_packages,id', 'category_id' => 'nullable|integer|exists:vehicle_categories,id']);
        if (!empty($data['category_id'])) {
            $lead->update(['category_id' => $data['category_id']]);
        }

        $message = $this->leads->sendQuote($lead->fresh(), (int) $data['package_id'], $request->user());

        return $this->success([
            'lead' => $this->leads->present($lead->fresh(), true),
            'message' => $message ? app(\App\Modules\Crm\Services\CrmMessageService::class)->present($message) : null,
        ], 'Quote ready to send');
    }

    /**
     * Rows from an Excel / CSV export (already parsed by the browser): name, phone, car,
     * requirement, source, campaign, date.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => 'required|array|min:1|max:2000',
            'rows.*.name' => 'nullable|string|max:150',
            'rows.*.phone' => 'required|string|max:30',
            'rows.*.email' => 'nullable|string|max:150',
            'rows.*.car_text' => 'nullable|string|max:120',
            'rows.*.requirement' => 'nullable|string|max:1000',
            'rows.*.campaign' => 'nullable|string|max:150',
            'source' => 'nullable|string|max:40',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $added = 0;
        $updated = 0;
        $failed = [];
        foreach ($data['rows'] as $i => $row) {
            try {
                [, $new] = $this->leads->capture([...$row, 'source' => $data['source'] ?? 'other', 'branch_id' => $data['branch_id'] ?? null], $request->user());
                $new ? $added++ : $updated++;
            } catch (CrmException $e) {
                $failed[] = ['row' => $i + 1, 'error' => $e->getMessage()];
            }
        }

        return $this->success(compact('added', 'updated', 'failed'), "{$added} leads added, {$updated} updated" . ($failed ? ', ' . count($failed) . ' skipped' : ''));
    }

    protected function rules(bool $create = true): array
    {
        return [
            'name' => 'nullable|string|max:150',
            'phone' => ($create ? 'required' : 'sometimes') . '|string|max:30',
            'alt_phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'city' => 'nullable|string|max:80',
            'car_text' => 'nullable|string|max:120',
            'registration_no' => 'nullable|string|max:20',
            'make_id' => 'nullable|integer|exists:vehicle_makes,id',
            'model_id' => 'nullable|integer|exists:vehicle_models,id',
            'category_id' => 'nullable|integer|exists:vehicle_categories,id',
            'fuel_type' => 'nullable|string|max:20',
            'interest' => 'nullable|string|max:30',
            'package_id' => 'nullable|integer|exists:service_packages,id',
            'requirement' => 'nullable|string|max:2000',
            'campaign' => 'nullable|string|max:150',
            'referred_by_customer_id' => 'nullable|integer|exists:customers,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'temperature' => 'nullable|in:hot,warm,cold',
        ];
    }

    protected function authorizeLead(Request $request, Lead $lead): void
    {
        $user = $request->user();
        abort_unless($user->hasPermission('crm.leads.manage') || $lead->assigned_to === $user->id || $lead->assigned_to === null || $user->hasPermission('crm.bookings.manage'), 404);
    }
}
