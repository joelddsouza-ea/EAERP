<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Employees: every user has one app login; the HR profile holds pay and attendance setup.
 * Pay figures are shown only to those who prepare payroll.
 */
class EmployeeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $branches = $viewer->isOwner() ? null : $viewer->getAccessibleBranchIds();

        $users = User::with(['roles:id,name,slug', 'branches:id,name'])
            ->when(!$request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($branches !== null, fn ($q) => $q->whereHas('branches', fn ($b) => $b->whereIn('branches.id', $branches)))
            ->orderBy('name')
            ->get();
        $profiles = EmployeeProfile::with('shift:id,name')->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        $custody = StaffCustodyEntry::whereIn('user_id', $users->pluck('id'))->groupBy('user_id')->selectRaw('user_id, SUM(amount) AS held')->pluck('held', 'user_id');
        $advances = SalaryAdvance::where('status', SalaryAdvance::OPEN)->whereIn('user_id', $users->pluck('id'))
            ->groupBy('user_id')->selectRaw('user_id, SUM(amount - recovered) AS due')->pluck('due', 'user_id');
        $pay = $viewer->hasPermission('hr.payroll.prepare');

        return $this->success($users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'is_active' => $u->is_active,
            'roles' => $u->roles->pluck('name'),
            'branches' => $u->branches->pluck('name'),
            'profile' => $this->presentProfile($profiles[$u->id] ?? null, $pay),
            'custody_held' => round((float) ($custody[$u->id] ?? 0), 2),
            'advance_due' => $pay ? round((float) ($advances[$u->id] ?? 0), 2) : null,
        ])->values());
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $profile = EmployeeProfile::with('shift:id,name')->where('user_id', $user->id)->first();

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'profile' => $this->presentProfile($profile, $request->user()->hasPermission('hr.payroll.prepare')),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $pay = $request->user()->hasPermission('hr.payroll.prepare');
        $profile = EmployeeProfile::firstOrNew(['user_id' => $user->id]);

        $rules = [
            'branch_id' => 'nullable|integer|exists:branches,id',
            'employee_code' => ['nullable', 'string', 'max:20', Rule::unique('employee_profiles', 'employee_code')->ignore($profile->id)],
            'designation' => 'nullable|string|max:80',
            'gender' => 'nullable|in:male,female,other',
            'date_joined' => 'nullable|date',
            'date_left' => 'nullable|date|after_or_equal:date_joined',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'weekly_off_day' => 'nullable|integer|between:0,6',
            'attendance_required' => 'boolean',
        ];
        if ($pay) {
            $rules += [
                'monthly_salary' => 'nullable|numeric|min:0|max:10000000',
                'basic_salary' => 'nullable|numeric|min:0|max:10000000',
                'ot_hourly_rate' => 'nullable|numeric|min:0|max:100000',
                'offday_work_amount' => 'nullable|numeric|min:0|max:100000',
                'pt_enabled' => 'boolean',
                'pf_enabled' => 'boolean',
                'esi_enabled' => 'boolean',
                'uan' => 'nullable|string|max:20',
                'esic_no' => 'nullable|string|max:20',
                'pan' => 'nullable|string|max:12',
                'bank_name' => 'nullable|string|max:100',
                'bank_account' => 'nullable|string|max:30',
                'bank_ifsc' => 'nullable|string|max:15',
            ];
        }
        $data = $request->validate($rules);
        if (array_key_exists('monthly_salary', $data)) {
            $data['monthly_salary'] ??= 0;
        }

        $profile->fill($data);
        $profile->branch_id ??= $user->primaryBranch()?->id ?? $user->branches()->value('branches.id');
        $profile->save();

        return $this->success($this->presentProfile($profile->fresh('shift'), $pay), 'Employee details saved');
    }

    protected function presentProfile(?EmployeeProfile $p, bool $pay): ?array
    {
        if (!$p) {
            return null;
        }

        $out = [
            'branch_id' => $p->branch_id,
            'employee_code' => $p->employee_code,
            'designation' => $p->designation,
            'gender' => $p->gender,
            'date_joined' => $p->date_joined?->toDateString(),
            'date_left' => $p->date_left?->toDateString(),
            'shift_id' => $p->shift_id,
            'shift' => $p->shift?->name,
            'weekly_off_day' => $p->weekly_off_day,
            'attendance_required' => $p->attendance_required,
        ];

        if ($pay) {
            $out += [
                'monthly_salary' => (float) $p->monthly_salary,
                'basic_salary' => $p->basic_salary !== null ? (float) $p->basic_salary : null,
                'ot_hourly_rate' => $p->ot_hourly_rate !== null ? (float) $p->ot_hourly_rate : null,
                'offday_work_amount' => $p->offday_work_amount !== null ? (float) $p->offday_work_amount : null,
                'pt_enabled' => $p->pt_enabled,
                'pf_enabled' => $p->pf_enabled,
                'esi_enabled' => $p->esi_enabled,
                'uan' => $p->uan,
                'esic_no' => $p->esic_no,
                'pan' => $p->pan,
                'bank_name' => $p->bank_name,
                'bank_account' => $p->bank_account,
                'bank_ifsc' => $p->bank_ifsc,
            ];
        }

        return $out;
    }

    /**
     * Who can be picked as having collected a customer's money.
     */
    public function directory(Request $request): JsonResponse
    {
        return $this->success(User::active()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values());
    }
}
