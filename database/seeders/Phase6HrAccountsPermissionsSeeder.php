<?php

namespace Database\Seeders;

use App\Core\Models\Permission;
use App\Core\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Attendance & payroll and accounting (Phase 6), and the plain "Employee" role for staff
 * who only punch, apply for leave and see their payslips.
 *
 * Everyone gets hr.self. Salaries are seen by those who prepare payroll (owner, manager,
 * back office); the owner finalises. Idempotent and additive (syncWithoutDetaching).
 */
class Phase6HrAccountsPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'hr.self' => 'Punch in / out with a selfie, apply for leave, see my payslips',
        'hr.attendance.view' => 'View the attendance register',
        'hr.attendance.manage' => "Add or remove a punch (regularise) with a reason",
        'hr.leave.approve' => 'Approve or reject leave',
        'hr.employees.manage' => 'Edit employee details (designation, shift, weekly off)',
        'hr.payroll.prepare' => 'See salaries; work out payroll, adjustments, incentives',
        'hr.payroll.finalise' => 'Finalise or reopen a payroll',
        'hr.payroll.pay' => 'Mark salaries as paid',
        'hr.advances.manage' => 'Give and manage salary advances',
        'hr.custody.manage' => 'Record customer money handed over by staff',
        'hr.settings.manage' => 'Edit HR & payroll masters (shifts, holidays, leave types, incentive rules, statutory)',
        'accounts.view' => 'View expenses and vouchers',
        'accounts.reports.view' => 'View accounts reports (P&L, balance sheet, ledgers, GST)',
        'accounts.expenses.create' => 'Record expenses',
        'accounts.journals.create' => 'Post journal / contra vouchers, cancel expenses',
        'accounts.settings.manage' => 'Edit the chart of accounts, bank accounts, books start',
        'accounts.period.lock' => 'Lock or unlock a month',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'module' => str_starts_with($slug, 'hr.') ? 'hr' : 'accounts',
                'description' => $name,
            ]);
        }

        Role::firstOrCreate(['slug' => Role::EMPLOYEE], [
            'name' => 'Employee',
            'description' => 'Attendance, leave and payslips only',
            'is_system' => true,
        ]);

        $all = array_keys(self::PERMISSIONS);
        $managerHr = ['hr.attendance.view', 'hr.attendance.manage', 'hr.leave.approve', 'hr.employees.manage'];

        $grants = [
            Role::OWNER => $all,
            Role::MANAGER => [...$managerHr, 'hr.payroll.prepare', 'hr.advances.manage', 'hr.custody.manage', 'accounts.view', 'accounts.reports.view', 'accounts.expenses.create'],
            Role::BODY_SHOP_MANAGER => [...$managerHr, 'accounts.expenses.create'],
            Role::BACKOFFICE => [
                'hr.attendance.view', 'hr.employees.manage', 'hr.payroll.prepare', 'hr.payroll.pay', 'hr.advances.manage', 'hr.custody.manage',
                'accounts.view', 'accounts.reports.view', 'accounts.expenses.create', 'accounts.journals.create',
            ],
            Role::SERVICE_ADVISOR => ['accounts.expenses.create'],
            Role::BODY_SHOP_ADVISOR => ['accounts.expenses.create'],
            Role::SPARE_PARTS_COORDINATOR => ['accounts.expenses.create'],
        ];

        // Every role punches and applies for leave
        $self = Permission::where('slug', 'hr.self')->pluck('id');
        foreach (Role::all() as $role) {
            $role->permissions()->syncWithoutDetaching($self);
        }

        foreach ($grants as $roleSlug => $slugs) {
            Role::where('slug', $roleSlug)->first()?->permissions()
                ->syncWithoutDetaching(Permission::whereIn('slug', $slugs)->pluck('id'));
        }
    }
}
