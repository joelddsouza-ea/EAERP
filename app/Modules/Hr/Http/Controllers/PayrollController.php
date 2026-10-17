<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Hr\Models\PayrollAdjustment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\PayslipLine;
use App\Modules\Hr\Services\HrException;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollController extends ApiController
{
    public function __construct(protected PayrollService $payroll) {}

    public function index(): JsonResponse
    {
        return $this->success(PayrollRun::latest('period')->latest('id')->limit(36)->get()->map(fn ($r) => $this->payroll->presentRun($r))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => 'required|string|size:7',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $run = $this->payroll->createRun($data['period'], $data['branch_id'] ?? null, $request->user());

        return $this->created($this->payroll->presentRun($run, true), 'Payroll worked out');
    }

    public function show(PayrollRun $run): JsonResponse
    {
        return $this->success($this->payroll->presentRun($run, true));
    }

    public function calculate(PayrollRun $run): JsonResponse
    {
        return $this->success($this->payroll->presentRun($this->payroll->calculate($run), true), 'Payroll recalculated');
    }

    public function editLine(Request $request, PayslipLine $line): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0|max:10000000',
            'note' => 'required|string|max:500',
        ]);
        $this->payroll->editLine($line, (float) $data['amount'], $data['note']);

        return $this->success($this->payroll->presentRun($line->payslip->run, true), 'Line changed');
    }

    public function finalise(Request $request, PayrollRun $run): JsonResponse
    {
        $run = $this->payroll->finalise($run, $request->user(), $request->boolean('force'));

        return $this->success($this->payroll->presentRun($run, true), 'Payroll finalised');
    }

    public function reopen(PayrollRun $run): JsonResponse
    {
        return $this->success($this->payroll->presentRun($this->payroll->reopen($run), true), 'Payroll reopened');
    }

    public function pay(Request $request, PayrollRun $run): JsonResponse
    {
        $data = $request->validate([
            'payslip_ids' => 'nullable|array',
            'payslip_ids.*' => 'integer',
            'method' => 'required|in:cash,bank_transfer,upi,cheque',
            'paid_on' => 'nullable|date|before_or_equal:today',
            'reference' => 'nullable|string|max:100',
        ]);

        $run = $this->payroll->markPaid($run, $data['payslip_ids'] ?? [], $data, $request->user());

        return $this->success($this->payroll->presentRun($run, true), 'Salary marked as paid');
    }

    public function destroy(PayrollRun $run): JsonResponse
    {
        if (!$run->isDraft()) {
            throw new HrException('Only a draft payroll can be deleted.');
        }
        $run->payslips()->each(function ($s) {
            $s->lines()->delete();
            $s->delete();
        });
        $run->delete();

        return $this->success(null, 'Draft payroll deleted');
    }

    // ------------------------------------------------------------- adjustments

    public function adjustments(Request $request): JsonResponse
    {
        $period = $request->query('period', now()->format('Y-m'));

        return $this->success(PayrollAdjustment::with(['user:id,name', 'createdBy:id,name'])->where('period', $period)->orderBy('user_id')->get()->map(fn (PayrollAdjustment $a) => [
            'id' => $a->id,
            'user_id' => $a->user_id,
            'employee' => $a->user?->name,
            'period' => $a->period,
            'kind' => $a->kind,
            'amount' => $a->amount,
            'note' => $a->note,
            'by' => $a->createdBy?->name,
        ])->values());
    }

    public function addAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'period' => 'required|string|size:7',
            'kind' => ['required', Rule::in([...array_keys(PayrollAdjustment::EARNINGS), ...array_keys(PayrollAdjustment::DEDUCTIONS)])],
            'amount' => 'required|numeric|min:0.01|max:10000000',
            'note' => 'nullable|string|max:500',
        ]);
        if ($this->payroll->isLocked((int) $data['user_id'], $data['period'])) {
            throw new HrException('Payroll for that month is finalised.');
        }

        $adj = PayrollAdjustment::create([...$data, 'created_by' => $request->user()->id]);

        $label = PayrollAdjustment::EARNINGS[$adj->kind] ?? PayrollAdjustment::DEDUCTIONS[$adj->kind];

        return $this->created(['id' => $adj->id], "{$label} added. Recalculate the payroll to include it.");
    }

    public function deleteAdjustment(PayrollAdjustment $adjustment): JsonResponse
    {
        if ($this->payroll->isLocked($adjustment->user_id, $adjustment->period)) {
            throw new HrException('Payroll for that month is finalised.');
        }
        $adjustment->delete();

        return $this->success(null, 'Removed');
    }
}
