<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Branch;
use App\Core\Services\ExportService;
use App\Core\Support\IndianCurrency;
use App\Modules\Hr\Models\AttendanceDay;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\SalaryAdvance;
use App\Modules\Hr\Models\StaffCustodyEntry;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\StaffMoneyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The employee's own screen: punch in / out, attendance, leave, payslips, advances.
 */
class SelfServiceController extends ApiController
{
    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
        protected PayrollService $payroll,
        protected StaffMoneyService $money,
    ) {}

    public function today(Request $request): JsonResponse
    {
        return $this->success($this->attendance->today($request->user()));
    }

    public function punch(Request $request): JsonResponse
    {
        $request->validate([
            'selfie' => 'required|image|max:' . (int) config('workshop.job_sheets.max_photo_kb'),
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'captured_at' => 'nullable|date',
            'client_time' => 'nullable|date',
            'device' => 'nullable|string|max:255',
        ], ['selfie.required' => 'Take a selfie to punch.']);

        $punch = $this->attendance->punch($request->user(), $request->file('selfie'), $request->only(['lat', 'lng', 'accuracy', 'captured_at', 'client_time', 'device']));

        return $this->created(
            $this->attendance->today($request->user()),
            $punch->type === AttendancePunch::IN ? 'Punched in at ' . $punch->punched_at->format('H:i') : 'Punched out at ' . $punch->punched_at->format('H:i')
        );
    }

    /**
     * The month's days (and totals) for the signed-in employee.
     */
    public function attendance(Request $request): JsonResponse
    {
        $period = $request->query('period', now()->format('Y-m'));

        return $this->success($this->month($request->user()->id, $period));
    }

    public function month(int $userId, string $period): array
    {
        $start = Carbon::parse($period . '-01');
        $end = $start->copy()->endOfMonth();
        $this->attendance->rebuildRange($userId, $start->toDateString(), min($end->toDateString(), today()->subDay()->toDateString()));

        $days = AttendanceDay::where('user_id', $userId)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->orderBy('date')->get();
        $counts = $days->countBy('status');

        return [
            'period' => $period,
            'days' => $days->map(fn ($d) => $this->attendance->presentDay($d))->values(),
            'summary' => [
                'present' => ($counts[AttendanceDay::PRESENT] ?? 0) + 0.5 * ($counts[AttendanceDay::HALF_DAY] ?? 0),
                'half_days' => $counts[AttendanceDay::HALF_DAY] ?? 0,
                'absent' => $counts[AttendanceDay::ABSENT] ?? 0,
                'leave_paid' => $counts[AttendanceDay::LEAVE_PAID] ?? 0,
                'leave_unpaid' => $counts[AttendanceDay::LEAVE_UNPAID] ?? 0,
                'weekly_off' => $counts[AttendanceDay::WEEKLY_OFF] ?? 0,
                'holidays' => $counts[AttendanceDay::HOLIDAY] ?? 0,
                'incomplete' => $counts[AttendanceDay::INCOMPLETE] ?? 0,
                'late_days' => $days->where('late_minutes', '>', 0)->count(),
                'ot_hours' => round($days->sum('ot_minutes') / 60, 2),
                'off_days_worked' => $days->where('worked_off_day', true)->count(),
            ],
        ];
    }

    public function leave(Request $request): JsonResponse
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);

        return $this->success([
            'balances' => $this->leave->balances($user->id, $year),
            'requests' => LeaveRequest::where('user_id', $user->id)->latest('from_date')->limit(50)->get()
                ->map(fn ($r) => $this->leave->present($r))->values(),
        ]);
    }

    public function applyLeave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'from_date' => 'required|date',
            'to_date' => 'nullable|date',
            'half_day' => 'boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        $leave = $this->leave->apply($request->user(), $data);

        return $this->created($this->leave->present($leave), 'Leave request sent' . ($leave->unpaid_days > 0 ? " ({$leave->unpaid_days} day(s) without pay)" : ''));
    }

    public function cancelLeave(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        abort_unless($leaveRequest->user_id === $request->user()->id, 404);

        return $this->success($this->leave->present($this->leave->cancel($leaveRequest, $request->user())), 'Leave cancelled');
    }

    public function payslips(Request $request): JsonResponse
    {
        $slips = Payslip::with(['run', 'lines'])
            ->where('user_id', $request->user()->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [PayrollRun::FINALISED, PayrollRun::PAID]))
            ->latest('id')
            ->limit(24)
            ->get();

        return $this->success($slips->map(fn ($s) => $this->payroll->presentSlip($s))->values());
    }

    public function payslipPdf(Request $request, Payslip $payslip, ExportService $export)
    {
        $user = $request->user();
        $own = $payslip->user_id === $user->id;
        abort_unless($own || $user->hasPermission('hr.payroll.prepare'), 404);
        if ($own) {
            abort_if($payslip->run->isDraft(), 404);
        }

        $slip = $this->payroll->presentSlip($payslip);
        $branch = Branch::with('organization')->find($payslip->branch_id);
        $company = [
            'name' => $branch?->organization?->name ?? config('app.name'),
            'address' => collect([$branch?->address, $branch?->city])->filter()->implode(', '),
        ];

        return $export->toPdf('exports.payslip', [
            'company' => $company,
            'slip' => $slip,
            'words' => IndianCurrency::inWords(max(0, $slip['net'])),
        ], 'payslip-' . str_replace(' ', '-', strtolower($slip['employee'])) . '-' . $slip['period'], options: ['enable_font_subsetting' => true]);
    }

    /**
     * Advances outstanding and customer money held, for the employee.
     */
    public function money(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'advances' => SalaryAdvance::where('user_id', $user->id)->where('status', SalaryAdvance::OPEN)->get()
                ->map(fn ($a) => $this->money->presentAdvance($a))->values(),
            'custody_balance' => StaffCustodyEntry::balanceFor($user->id),
            'custody_entries' => StaffCustodyEntry::where('user_id', $user->id)->latest('entry_date')->latest('id')->limit(30)->get()
                ->map(fn ($e) => $this->money->presentCustody($e))->values(),
        ]);
    }

    /**
     * Selfie files: authorised by a short-lived URL signature.
     */
    public function selfie(Request $request, AttendancePunch $punch): BinaryFileResponse
    {
        $path = $request->boolean('thumb') ? ($punch->selfie_thumb_path ?? $punch->selfie_path) : $punch->selfie_path;
        $disk = Storage::disk($punch->selfie_disk ?: 'job_media');
        abort_unless($path && $disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => $request->boolean('thumb') ? 'image/jpeg' : ($disk->mimeType($path) ?: 'image/jpeg'),
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
