<?php

use App\Modules\Hr\Http\Controllers\AttendanceController;
use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\HrMasterController;
use App\Modules\Hr\Http\Controllers\LeaveController;
use App\Modules\Hr\Http\Controllers\PayrollController;
use App\Modules\Hr\Http\Controllers\SelfServiceController;
use App\Modules\Hr\Http\Controllers\StaffMoneyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/hr')->middleware(['auth:sanctum', 'branch.context'])->group(function () {
    // The employee's own: punch, attendance, leave, payslips, advances
    Route::middleware('permission:hr.self')->prefix('me')->group(function () {
        Route::get('today', [SelfServiceController::class, 'today']);
        Route::post('punch', [SelfServiceController::class, 'punch'])->middleware('throttle:20,1');
        Route::get('attendance', [SelfServiceController::class, 'attendance']);
        Route::get('leave', [SelfServiceController::class, 'leave']);
        Route::post('leave', [SelfServiceController::class, 'applyLeave']);
        Route::post('leave/{leaveRequest}/cancel', [SelfServiceController::class, 'cancelLeave']);
        Route::get('payslips', [SelfServiceController::class, 'payslips']);
        Route::get('money', [SelfServiceController::class, 'money']);
    });
    Route::get('payslips/{payslip}/pdf', [SelfServiceController::class, 'payslipPdf']);

    // Staff names for "collected by" on a payment
    Route::get('staff-directory', [EmployeeController::class, 'directory'])->middleware('permission:billing.payments.create|accounts.expenses.create|hr.custody.manage|hr.payroll.prepare|hr.advances.manage');

    // Masters (read for anyone who manages attendance or payroll; edit with the settings permission)
    Route::get('masters', [HrMasterController::class, 'index'])->middleware('permission:hr.settings.manage|hr.attendance.view|hr.payroll.prepare|hr.employees.manage');
    Route::middleware('permission:hr.settings.manage')->prefix('masters')->group(function () {
        Route::post('shifts', [HrMasterController::class, 'saveShift']);
        Route::put('shifts/{shift}', [HrMasterController::class, 'saveShift']);
        Route::post('holidays', [HrMasterController::class, 'saveHoliday']);
        Route::put('holidays/{holiday}', [HrMasterController::class, 'saveHoliday']);
        Route::delete('holidays/{holiday}', [HrMasterController::class, 'deleteHoliday']);
        Route::post('leave-types', [HrMasterController::class, 'saveLeaveType']);
        Route::put('leave-types/{leaveType}', [HrMasterController::class, 'saveLeaveType']);
        Route::post('incentive-rules', [HrMasterController::class, 'saveIncentiveRule']);
        Route::put('incentive-rules/{rule}', [HrMasterController::class, 'saveIncentiveRule']);
        Route::delete('incentive-rules/{rule}', [HrMasterController::class, 'deleteIncentiveRule']);
        Route::put('settings', [HrMasterController::class, 'saveSettings']);
    });

    // Attendance register
    Route::middleware('permission:hr.attendance.view')->group(function () {
        Route::get('attendance/day', [AttendanceController::class, 'day']);
        Route::get('attendance/month', [AttendanceController::class, 'month']);
        Route::get('attendance/users/{user}/punches', [AttendanceController::class, 'punches']);
    });
    Route::middleware('permission:hr.attendance.manage')->group(function () {
        Route::post('attendance/users/{user}/punches', [AttendanceController::class, 'addPunch']);
        Route::post('attendance/punches/{punch}/void', [AttendanceController::class, 'voidPunch']);
    });

    // Leave approvals
    Route::middleware('permission:hr.leave.approve')->group(function () {
        Route::get('leave-requests', [LeaveController::class, 'index']);
        Route::get('leave-requests/pending-count', [HrMasterController::class, 'pendingLeave']);
        Route::post('leave-requests/{leaveRequest}/decide', [LeaveController::class, 'decide']);
        Route::post('leave-requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel']);
        Route::get('leave-balances/{userId}', [LeaveController::class, 'balances']);
    });

    // Employees
    Route::middleware('permission:hr.employees.manage')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::get('employees/{user}', [EmployeeController::class, 'show']);
        Route::put('employees/{user}/profile', [EmployeeController::class, 'update']);
    });

    // Payroll
    Route::middleware('permission:hr.payroll.prepare')->group(function () {
        Route::get('payroll-runs', [PayrollController::class, 'index']);
        Route::post('payroll-runs', [PayrollController::class, 'store']);
        Route::get('payroll-runs/{run}', [PayrollController::class, 'show']);
        Route::post('payroll-runs/{run}/calculate', [PayrollController::class, 'calculate']);
        Route::delete('payroll-runs/{run}', [PayrollController::class, 'destroy']);
        Route::put('payslip-lines/{line}', [PayrollController::class, 'editLine']);
        Route::get('adjustments', [PayrollController::class, 'adjustments']);
        Route::post('adjustments', [PayrollController::class, 'addAdjustment']);
        Route::delete('adjustments/{adjustment}', [PayrollController::class, 'deleteAdjustment']);
    });
    Route::middleware('permission:hr.payroll.finalise')->group(function () {
        Route::post('payroll-runs/{run}/finalise', [PayrollController::class, 'finalise']);
        Route::post('payroll-runs/{run}/reopen', [PayrollController::class, 'reopen']);
    });
    Route::post('payroll-runs/{run}/pay', [PayrollController::class, 'pay'])->middleware('permission:hr.payroll.pay');

    // Advances and customer money held by staff
    Route::middleware('permission:hr.advances.manage')->group(function () {
        Route::get('advances', [StaffMoneyController::class, 'advances']);
        Route::post('advances', [StaffMoneyController::class, 'giveAdvance']);
        Route::put('advances/{advance}', [StaffMoneyController::class, 'updateAdvance']);
        Route::post('advances/{advance}/cancel', [StaffMoneyController::class, 'cancelAdvance']);
    });
    Route::middleware('permission:hr.custody.manage')->group(function () {
        Route::get('custody', [StaffMoneyController::class, 'custody']);
        Route::post('custody/hand-over', [StaffMoneyController::class, 'handOver']);
    });
});

// Attendance selfies: authorised by a short-lived URL signature
Route::get('v1/hr/selfies/{punch}', [SelfServiceController::class, 'selfie'])
    ->middleware('signed')
    ->name('hr.selfie.stream');
