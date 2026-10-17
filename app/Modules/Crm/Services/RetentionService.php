<?php

namespace App\Modules\Crm\Services;

use App\Core\Models\Role;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\JournalLine;
use App\Modules\Accounts\Services\LedgerService;
use App\Modules\Accounts\Support\ChartOfAccounts as Coa;
use App\Modules\Billing\Models\CustomerCredit;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Models\Feedback;
use App\Modules\Crm\Models\JourneyEnrollment;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Referral;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Notifications\StaffAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeping customers:
 *  - at delivery: the lead that brought the job is won (with the bill as its revenue),
 *    the thank-you / feedback journey starts, work the customer declined becomes a
 *    pending-work journey, and a referral earns its reward;
 *  - daily: next-service-due, insurance renewal and win-back journeys;
 *  - feedback: a low rating alerts the manager, a high one is asked for a Google review.
 */
class RetentionService
{
    public function __construct(
        protected JourneyService $journeys,
        protected LeadService $leads,
    ) {}

    // ------------------------------------------------------------ job events

    public function onJobStatus(int $jobCardId, string $toStatus): void
    {
        if ($toStatus !== JobCardStatus::Delivered->value) {
            return;
        }

        $job = JobCard::withoutGlobalScopes()->with(['customer', 'vehicle'])->find($jobCardId);
        if (!$job || !$job->customer) {
            return;
        }

        $this->winLeads($job);
        $this->journeys->stopForCustomer($job->customer_id, $job->vehicle_id, ['service_due', 'win_back', 'pending_work'], 'visited');
        $this->journeys->enroll('after_delivery', ['customer' => $job->customer, 'vehicle' => $job->vehicle, 'job_card' => $job]);
        if ($this->hasPendingWork($job)) {
            $this->journeys->enroll('pending_work', ['customer' => $job->customer, 'vehicle' => $job->vehicle, 'job_card' => $job]);
        }
        $this->rewardReferral($job);
    }

    /**
     * The booking's lead (and any other open lead of this customer) is won.
     */
    protected function winLeads(JobCard $job): void
    {
        $bill = app(BillingService::class)->currentBill($job);
        $revenue = $bill ? (float) $bill->total_amount : 0.0;
        $phone10 = substr(preg_replace('/\D+/', '', (string) $job->customer->phone), -10);

        $leadIds = Booking::where('job_card_id', $job->id)->whereNotNull('lead_id')->pluck('lead_id');
        $more = Lead::open()
            ->where(fn ($q) => $q->where('customer_id', $job->customer_id)->when(strlen($phone10) === 10, fn ($q) => $q->orWhere('phone', 'like', "%{$phone10}")))
            ->pluck('id');

        $first = true;
        foreach (Lead::whereIn('id', $leadIds->merge($more)->unique())->get() as $lead) {
            if (!$lead->isOpen()) {
                continue;
            }
            // The revenue is credited once, to the lead that booked (else the first)
            $lead->update([
                'won_revenue' => $first ? $revenue : 0,
                'won_job_card_id' => $job->id,
                'customer_id' => $job->customer_id,
                'vehicle_id' => $lead->vehicle_id ?? $job->vehicle_id,
            ]);
            $this->leads->setStatus($lead, Lead::WON);
            $this->leads->log($lead, 'status', null, "Delivered — job {$job->job_number}" . ($first && $revenue ? ', Rs ' . number_format($revenue, 0) : ''));
            $first = false;
        }
    }

    protected function hasPendingWork(JobCard $job): bool
    {
        $estimate = JobCardEstimate::where('job_card_id', $job->id)->where('status', JobCardEstimate::STATUS_SENT)->latest('version')->first();
        $declined = $estimate && JobCardEstimateLine::where('estimate_id', $estimate->id)->where('approval_status', JobCardEstimateLine::DECLINED)->exists();

        return $declined || JobCardIssue::where('job_card_id', $job->id)->where('status', 'deferred')->exists();
    }

    /**
     * The referred friend's first delivered job earns the referrer credit.
     */
    protected function rewardReferral(JobCard $job): void
    {
        $referral = Referral::where('status', Referral::PENDING)
            ->where(fn ($q) => $q->where('referred_customer_id', $job->customer_id)
                ->orWhereIn('lead_id', Lead::where('customer_id', $job->customer_id)->pluck('id')))
            ->first();
        if (!$referral) {
            return;
        }

        $earlier = JobCard::withoutGlobalScopes()->where('customer_id', $job->customer_id)->where('id', '!=', $job->id)->where('status', JobCardStatus::Delivered->value)->exists();
        if ($earlier) {
            return;
        }

        $amount = round((float) WorkshopSettings::get('crm.referral_reward'), 2);
        DB::transaction(function () use ($referral, $job, $amount) {
            $credit = $amount > 0 ? CustomerCredit::create([
                'branch_id' => $job->branch_id,
                'customer_id' => $referral->referrer_customer_id,
                'amount' => $amount,
                'note' => "Referral reward — {$job->customer->name} ({$job->job_number})",
            ]) : null;

            $referral->update([
                'status' => Referral::REWARDED,
                'referred_customer_id' => $job->customer_id,
                'job_card_id' => $job->id,
                'reward_amount' => $amount,
                'customer_credit_id' => $credit?->id,
                'rewarded_at' => now(),
            ]);

            // The reward is a marketing cost owed to the referrer as credit
            if ($credit && \App\Core\Support\Modules::accounts()) {
                $marketing = Account::where('code', '6270')->value('id') ?? Coa::id(Coa::SALES_OTHER);
                app(LedgerService::class)->sync('referral', $referral->id, 'journal', today()->toDateString(), $job->branch_id, [
                    ['account' => $marketing, 'debit' => $amount, 'memo' => 'Referral reward'],
                    ['account' => Coa::id(Coa::DEBTORS), 'credit' => $amount, 'party' => [JournalLine::PARTY_CUSTOMER, (int) $referral->referrer_customer_id]],
                ], 'Referral reward');
            }
        });

        $referrer = Customer::find($referral->referrer_customer_id);
        if ($referrer) {
            $this->journeys->enroll('referral_thanks', ['customer' => $referrer, 'branch_id' => $job->branch_id]);
        }
    }

    // ----------------------------------------------------------------- daily

    /**
     * Enrol cars coming up for service / insurance, and customers who have gone quiet.
     *
     * @return array{service_due: int, insurance: int, win_back: int}
     */
    public function daily(): array
    {
        $counts = ['service_due' => 0, 'insurance' => 0, 'win_back' => 0];

        Vehicle::with('customer')
            ->whereNotNull('last_service_at')
            ->where(fn ($q) => $q->whereNotNull('next_service_due_date')->orWhereNotNull('next_service_due_km'))
            ->lazyById()
            ->each(function (Vehicle $v) use (&$counts) {
                $due = $this->serviceDueDate($v);
                if (!$v->customer || !$due || $due->gt(today()->addDays(21)) || $due->lt(today()->subDays(35))) {
                    return;
                }
                if ($this->recentlyEnrolled($v->customer_id, $v->id, 'service_due', $due->copy()->subDays(60))) {
                    return;
                }
                $counts['service_due'] += $this->journeys->enroll('service_due', ['customer' => $v->customer, 'vehicle' => $v, 'anchor' => $due->toDateString(), 'branch_id' => $this->lastBranch($v)]);
            });

        Vehicle::with('customer')
            ->whereBetween('insurance_expiry', [today()->toDateString(), today()->addDays(30)->toDateString()])
            ->lazyById()
            ->each(function (Vehicle $v) use (&$counts) {
                if ($v->customer && !$this->recentlyEnrolled($v->customer_id, $v->id, 'insurance_due', today()->subDays(60))) {
                    $counts['insurance'] += $this->journeys->enroll('insurance_due', ['customer' => $v->customer, 'vehicle' => $v, 'anchor' => $v->insurance_expiry->toDateString(), 'branch_id' => $this->lastBranch($v)]);
                }
            });

        $months = (int) WorkshopSettings::get('crm.win_back_months');
        if ($months > 0) {
            $cutoff = today()->subMonths($months);
            $quiet = JobCard::withoutGlobalScopes()
                ->where('status', JobCardStatus::Delivered->value)
                ->groupBy('customer_id', 'vehicle_id')
                ->selectRaw('customer_id, vehicle_id, MAX(updated_at) AS last_visit, MAX(branch_id) AS branch_id')
                ->havingRaw('MAX(updated_at) < ?', [$cutoff])
                ->limit(500)
                ->get();
            foreach ($quiet as $row) {
                if ($this->recentlyEnrolled((int) $row->customer_id, $row->vehicle_id ? (int) $row->vehicle_id : null, 'win_back', today()->subMonths($months))) {
                    continue;
                }
                $customer = Customer::find($row->customer_id);
                if ($customer) {
                    $counts['win_back'] += $this->journeys->enroll('win_back', ['customer' => $customer, 'vehicle' => Vehicle::find($row->vehicle_id), 'branch_id' => (int) $row->branch_id]);
                }
            }
        }

        $this->leads->decayScores();

        return $counts;
    }

    /**
     * The earlier of the date due and when the km due will be reached at the car's usual
     * running (km per day across its visits).
     */
    public function serviceDueDate(Vehicle $v): ?Carbon
    {
        $byDate = $v->next_service_due_date ? $v->next_service_due_date->copy() : null;
        $byKm = null;

        if ($v->next_service_due_km && $v->last_service_km && $v->last_service_at) {
            $visits = JobCard::withoutGlobalScopes()->where('vehicle_id', $v->id)->whereNotNull('km_reading')->orderBy('created_at')->get(['km_reading', 'created_at']);
            if ($visits->count() >= 2) {
                $first = $visits->first();
                $last = $visits->last();
                $days = max(1, $first->created_at->diffInDays($last->created_at, true));
                $perDay = max(1, ($last->km_reading - $first->km_reading) / $days);
                $byKm = $v->last_service_at->copy()->addDays((int) ceil(max(0, $v->next_service_due_km - $v->last_service_km) / $perDay));
            }
        }

        return $byDate && $byKm ? ($byKm->lt($byDate) ? $byKm : $byDate) : ($byDate ?? $byKm);
    }

    protected function recentlyEnrolled(int $customerId, ?int $vehicleId, string $trigger, Carbon $since): bool
    {
        return JourneyEnrollment::where('customer_id', $customerId)
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->where('created_at', '>=', $since)
            ->whereHas('journey', fn ($q) => $q->where('trigger', $trigger))
            ->exists();
    }

    protected function lastBranch(Vehicle $v): ?int
    {
        return JobCard::withoutGlobalScopes()->where('vehicle_id', $v->id)->latest('id')->value('branch_id');
    }

    // -------------------------------------------------------------- feedback

    /**
     * The customer's rating from their link.
     *
     * @return array{feedback: Feedback, review_url: ?string}
     */
    public function submitFeedback(CrmLink $link, int $rating, ?string $comments): array
    {
        if (!$link->job_card_id) {
            throw new CrmException('This link is not for a service.');
        }
        $job = JobCard::withoutGlobalScopes()->with('customer')->findOrFail($link->job_card_id);
        $rating = max(1, min(5, $rating));

        $feedback = Feedback::updateOrCreate(['job_card_id' => $job->id], [
            'customer_id' => $job->customer_id,
            'branch_id' => $job->branch_id,
            'rating' => $rating,
            'comments' => $comments,
        ]);

        if ($rating <= 3 && $feedback->wasRecentlyCreated) {
            $alert = new StaffAlert('feedback_low', "{$rating}★ feedback — {$job->job_number}", ($job->customer?->name ?? 'Customer') . ($comments ? ": {$comments}" : ' rated the service low.') . ' Call them back.', "/job-cards/{$job->id}", $job->id);
            User::active()
                ->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::OWNER, Role::MANAGER]))
                ->when($job->service_advisor_id, fn ($q) => $q->orWhere('id', $job->service_advisor_id))
                ->get()
                ->each->notify($alert);
        }

        $url = trim((string) WorkshopSettings::get('crm.google_review_url'));

        return ['feedback' => $feedback, 'review_url' => $rating >= 4 && $url ? $url : null];
    }
}
