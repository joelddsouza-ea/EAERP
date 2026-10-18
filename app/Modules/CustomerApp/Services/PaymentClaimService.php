<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Models\User;
use App\Core\Services\FileStorageService;
use App\Modules\Billing\Services\BillingService;
use App\Modules\CustomerApp\Models\PaymentClaim;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Pay by UPI without a gateway: the customer pays the workshop's UPI ID from their UPI app and
 * reports the transaction reference; back office checks the bank and confirms, which records
 * the payment on the job exactly as if it was entered at the counter.
 */
class PaymentClaimService
{
    public function __construct(
        protected BillingService $billing,
        protected FileStorageService $files,
        protected CustomerNotifier $notifier,
    ) {}

    public function claim(Customer $customer, JobCard $job, float $amount, string $utr, ?UploadedFile $screenshot = null): PaymentClaim
    {
        $utr = strtoupper(preg_replace('/\s+/', '', $utr));
        if (!preg_match('/^[A-Z0-9]{10,22}$/', $utr)) {
            throw new CustomerAppException('Enter the UPI reference (UTR) from your payment app: 12 digits.', ['utr' => ['Not a UPI reference.']]);
        }
        if (PaymentClaim::where('utr', $utr)->exists()) {
            throw new CustomerAppException('This UPI reference has already been sent.', ['utr' => ['Already sent.']]);
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new CustomerAppException('Enter the amount you paid.', ['amount' => ['Required.']]);
        }

        $summary = $this->billing->summary($job);
        $pending = (float) PaymentClaim::where('job_card_id', $job->id)->where('status', PaymentClaim::PENDING)->sum('amount');
        if ($summary['bill_total'] !== null && $amount > max(0, (float) $summary['balance']) - $pending + 0.001) {
            throw new CustomerAppException('That is more than the balance due of Rs ' . number_format(max(0, (float) $summary['balance'] - $pending), 0) . '.', ['amount' => ['More than the balance.']]);
        }

        $stored = $screenshot ? $this->files->uploadPrivateMedia($screenshot, "payment-claims/{$job->id}") : null;

        $claim = PaymentClaim::create([
            'job_card_id' => $job->id,
            'customer_id' => $customer->id,
            'branch_id' => $job->branch_id,
            'amount' => $amount,
            'utr' => $utr,
            'screenshot_disk' => $stored['disk'] ?? null,
            'screenshot_path' => $stored['path'] ?? null,
            'status' => PaymentClaim::PENDING,
        ]);

        $alert = new StaffAlert('upi_claim', "UPI payment to confirm — {$job->job_number}", "{$customer->name} paid Rs " . number_format($amount, 0) . " by UPI (ref {$utr}). Check the bank and confirm.", '/billing/upi-claims', $job->id);
        $this->backOffice($job->branch_id)->each->notify($alert);

        return $claim;
    }

    public function confirm(PaymentClaim $claim, User $user, ?float $amount = null): PaymentClaim
    {
        if ($claim->status !== PaymentClaim::PENDING) {
            throw new CustomerAppException('This payment was already ' . $claim->status . '.');
        }
        $job = $claim->jobCard;
        $amount = round($amount ?? (float) $claim->amount, 2);

        DB::transaction(function () use ($claim, $job, $user, $amount) {
            $payment = $this->billing->recordPayment($job, [
                'parts' => [['method' => 'upi', 'amount' => $amount, 'reference_number' => $claim->utr]],
                'notes' => 'UPI payment reported in the customer app',
            ], $user);
            $claim->update([
                'status' => PaymentClaim::CONFIRMED,
                'amount' => $amount,
                'payment_id' => $payment->id,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);
        });

        $this->notifier->notify($claim->customer_id, 'payment', 'Payment received', 'We have received Rs ' . number_format($amount, 0) . " for job {$job->job_number}. Thank you!", "/app/jobs/{$job->id}");

        return $claim->fresh();
    }

    public function reject(PaymentClaim $claim, User $user, string $reason): PaymentClaim
    {
        if ($claim->status !== PaymentClaim::PENDING) {
            throw new CustomerAppException('This payment was already ' . $claim->status . '.');
        }
        if (!trim($reason)) {
            throw new CustomerAppException('Give the reason (the customer sees it).', ['reason' => ['Required.']]);
        }
        $claim->update(['status' => PaymentClaim::REJECTED, 'reason' => mb_substr(trim($reason), 0, 255), 'decided_by' => $user->id, 'decided_at' => now()]);

        $job = $claim->jobCard;
        $this->notifier->notify($claim->customer_id, 'payment', 'Payment not found', "We could not find your UPI payment (ref {$claim->utr}) for job {$job->job_number}: {$claim->reason}. Please call us.", "/app/jobs/{$job->id}");

        return $claim;
    }

    public function present(PaymentClaim $c, bool $staff = false): array
    {
        $out = [
            'id' => $c->id,
            'amount' => (float) $c->amount,
            'utr' => $c->utr,
            'status' => $c->status,
            'reason' => $c->reason,
            'created_at' => $c->created_at?->toIso8601String(),
            'decided_at' => $c->decided_at?->toIso8601String(),
        ];

        if ($staff) {
            $c->loadMissing(['jobCard:id,job_number,branch_id,customer_id', 'customer:id,name,phone', 'decidedBy:id,name', 'payment:id,payment_number']);
            $out += [
                'job' => $c->jobCard ? ['id' => $c->jobCard->id, 'job_number' => $c->jobCard->job_number] : null,
                'customer' => $c->customer?->only(['id', 'name', 'phone']),
                'decided_by' => $c->decidedBy?->name,
                'payment_number' => $c->payment?->payment_number,
                'screenshot_url' => $c->screenshot_path ? \Illuminate\Support\Facades\URL::temporarySignedRoute('customer-app.claim-screenshot', now()->addMinutes(30), ['claim' => $c->id]) : null,
            ];
        }

        return $out;
    }

    protected function backOffice(?int $branchId)
    {
        return User::active()
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'billing.payments.create'))
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($w) => $w->where('branches.id', $branchId)))
            ->limit(15)->get();
    }
}
