<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Role;
use App\Core\Support\WorkshopSettings;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\IncentiveRule;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\Shift;
use App\Modules\Workshop\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Masters › HR & Payroll: shifts, holidays, leave types, incentive rules and the payroll
 * settings (OT, off-day pay, statutory switch and rates, punch rules).
 */
class HrMasterController extends ApiController
{
    public const SETTINGS = [
        'hr.max_gps_accuracy_m' => 'integer|min:10|max:2000',
        'hr.selfie_window_seconds' => 'integer|min:0|max:600',
        'hr.ot_threshold_minutes' => 'integer|min:0|max:600',
        'hr.ot_rounding_minutes' => 'integer|min:1|max:120',
        'hr.default_ot_rate' => 'numeric|min:0|max:100000',
        'hr.default_offday_amount' => 'numeric|min:0|max:100000',
        'hr.tracking.enabled' => 'boolean',
        'hr.tracking.interval_seconds' => 'integer|min:15|max:900',
        'hr.tracking.distance_filter_m' => 'integer|min:10|max:2000',
        'hr.tracking.max_accuracy_m' => 'integer|min:20|max:5000',
        'hr.tracking.stale_minutes' => 'integer|min:2|max:240',
        'hr.tracking.stop_minutes' => 'integer|min:2|max:240',
        'hr.tracking.retention_days' => 'integer|min:7|max:730',
        'hr.statutory.enabled' => 'boolean',
        'hr.statutory.pf_employee_rate' => 'numeric|min:0|max:50',
        'hr.statutory.pf_employer_rate' => 'numeric|min:0|max:50',
        'hr.statutory.pf_wage_ceiling' => 'numeric|min:0|max:1000000',
        'hr.statutory.esi_employee_rate' => 'numeric|min:0|max:20',
        'hr.statutory.esi_employer_rate' => 'numeric|min:0|max:20',
        'hr.statutory.esi_gross_limit' => 'numeric|min:0|max:1000000',
        'hr.statutory.pt_february_top' => 'numeric|min:0|max:10000',
    ];

    public function index(): JsonResponse
    {
        $settings = [];
        foreach (array_keys(self::SETTINGS) as $key) {
            $settings[$key] = WorkshopSettings::get($key);
        }
        $settings['hr.statutory.pt_slabs_male'] = WorkshopSettings::get('hr.statutory.pt_slabs_male');
        $settings['hr.statutory.pt_slabs_female'] = WorkshopSettings::get('hr.statutory.pt_slabs_female');

        return $this->success([
            'shifts' => Shift::orderBy('id')->get(),
            'holidays' => Holiday::orderBy('date')->whereYear('date', '>=', now()->year - 1)->get()->map(fn (Holiday $h) => [
                'id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'branch_id' => $h->branch_id,
            ]),
            'leave_types' => LeaveType::orderBy('sort_order')->orderBy('id')->get(),
            'incentive_rules' => IncentiveRule::orderBy('id')->get(),
            'incentive_bases' => IncentiveRule::BASES,
            'roles' => Role::orderBy('name')->get(['name', 'slug']),
            'plans' => ServicePackage::orderBy('sort_order')->get(['id', 'name']),
            'settings' => $settings,
        ]);
    }

    // ---------------------------------------------------------------- shifts

    public function saveShift(Request $request, ?Shift $shift = null): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'break_minutes' => 'required|integer|min:0|max:240',
            'late_grace_minutes' => 'required|integer|min:0|max:120',
            'half_day_minutes' => 'required|integer|min:0|max:720',
            'is_active' => 'boolean',
        ]);
        $shift ??= new Shift();
        $shift->fill($data)->save();

        return $this->success($shift->fresh(), 'Shift saved');
    }

    // -------------------------------------------------------------- holidays

    public function saveHoliday(Request $request, ?Holiday $holiday = null): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date',
            'name' => 'required|string|max:100',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);
        $holiday ??= new Holiday();
        $holiday->fill($data)->save();

        return $this->success($holiday->fresh(), 'Holiday saved');
    }

    public function deleteHoliday(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return $this->success(null, 'Holiday removed');
    }

    // ----------------------------------------------------------- leave types

    public function saveLeaveType(Request $request, ?LeaveType $leaveType = null): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'code' => ['required', 'string', 'max:10', Rule::unique('leave_types', 'code')->ignore($leaveType?->id)],
            'is_paid' => 'boolean',
            'annual_quota' => 'required|numeric|min:0|max:365',
            'carry_forward' => 'boolean',
            'max_carry' => 'nullable|numeric|min:0|max:365',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
        ]);
        $leaveType ??= new LeaveType();
        $leaveType->fill([...$data, 'max_carry' => $data['max_carry'] ?? 0])->save();

        return $this->success($leaveType->fresh(), 'Leave type saved');
    }

    // ------------------------------------------------------- incentive rules

    public function saveIncentiveRule(Request $request, ?IncentiveRule $rule = null): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'roles' => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,slug',
            'basis' => ['required', Rule::in(array_keys(IncentiveRule::BASES))],
            'package_id' => 'nullable|integer|exists:service_packages,id',
            'value' => 'required|numeric|min:0|max:1000000',
            'min_monthly_billing' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);
        if (str_starts_with($data['basis'], 'pct_') && $data['value'] > 100) {
            return $this->error('A percentage cannot be more than 100.', 422, ['value' => ['At most 100%.']]);
        }

        $rule ??= new IncentiveRule();
        $rule->fill([...$data, 'min_monthly_billing' => $data['min_monthly_billing'] ?? 0])->save();

        return $this->success($rule->fresh(), 'Incentive rule saved');
    }

    public function deleteIncentiveRule(IncentiveRule $rule): JsonResponse
    {
        $rule->delete();

        return $this->success(null, 'Incentive rule removed');
    }

    // -------------------------------------------------------------- settings

    public function saveSettings(Request $request): JsonResponse
    {
        $rules = [];
        foreach (self::SETTINGS as $key => $rule) {
            $rules[str_replace('.', '__', $key)] = "sometimes|{$rule}";
        }
        $rules['hr__statutory__pt_slabs_male'] = 'sometimes|array|min:1';
        $rules['hr__statutory__pt_slabs_female'] = 'sometimes|array|min:1';

        $input = [];
        foreach ($request->input('settings', []) as $key => $value) {
            $input[str_replace('.', '__', $key)] = $value;
        }
        $data = validator($input, $rules)->validate();

        foreach ($data as $key => $value) {
            $key = str_replace('__', '.', $key);
            if (str_ends_with($key, 'pt_slabs_male') || str_ends_with($key, 'pt_slabs_female')) {
                $value = collect($value)->map(fn ($s) => [isset($s[0]) && $s[0] !== '' && $s[0] !== null ? (float) $s[0] : null, (float) ($s[1] ?? 0)])->values()->all();
            }
            WorkshopSettings::set($key, $value);
        }

        return $this->index();
    }

    /**
     * Pending leave count for the badge on the menu.
     */
    public function pendingLeave(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'pending' => LeaveRequest::where('status', LeaveRequest::PENDING)
                ->when(!$user->isOwner(), fn ($q) => $q->whereIn('branch_id', $user->getAccessibleBranchIds()))
                ->count(),
        ]);
    }
}
