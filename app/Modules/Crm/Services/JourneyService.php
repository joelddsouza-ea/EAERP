<?php

namespace App\Modules\Crm\Services;

use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\Journey;
use App\Modules\Crm\Models\JourneyEnrollment;
use App\Modules\Crm\Models\JourneyStep;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Carbon\Carbon;

/**
 * Journeys: a trigger enrols a lead (or a customer's car), then each step runs on its day
 * — send a creative, give the executive a follow-up, or open a lead. Runs only in the
 * sending window, keeps marketing to at most one message per person every few days,
 * and stops when the person books, is closed, or asks not to be messaged.
 */
class JourneyService
{
    /** Kind of lead a journey opens */
    protected const LEAD_KINDS = [
        'service_due' => 'service_due',
        'pending_work' => 'pending_work',
        'win_back' => 'win_back',
        'insurance_due' => 'insurance_renewal',
        'after_delivery' => 'feedback',
    ];

    /**
     * @param array{lead?: Lead, customer?: Customer, vehicle?: ?Vehicle, job_card?: ?JobCard, anchor?: ?string, branch_id?: ?int} $ctx
     * @return int how many journeys it was enrolled in
     */
    public function enroll(string $trigger, array $ctx): int
    {
        $lead = $ctx['lead'] ?? null;
        $customer = $ctx['customer'] ?? $lead?->customer;
        $vehicle = $ctx['vehicle'] ?? null;
        $branchId = $ctx['branch_id'] ?? $lead?->branch_id ?? ($ctx['job_card'] ?? null)?->branch_id;
        $anchor = Carbon::parse($ctx['anchor'] ?? today())->startOfDay();

        $journeys = Journey::with('steps')
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->get();

        $count = 0;
        foreach ($journeys as $journey) {
            if ($journey->steps->isEmpty()) {
                continue;
            }
            $exists = JourneyEnrollment::where('journey_id', $journey->id)
                ->where('status', JourneyEnrollment::ACTIVE)
                ->when($lead && !$vehicle, fn ($q) => $q->where('lead_id', $lead->id))
                ->when(!$lead || $vehicle, fn ($q) => $q->where('customer_id', $customer?->id)->where('vehicle_id', $vehicle?->id))
                ->exists();
            if ($exists) {
                continue;
            }

            $enrollment = (new JourneyEnrollment([
                'journey_id' => $journey->id,
                'lead_id' => $lead?->id,
                'customer_id' => $customer?->id,
                'vehicle_id' => $vehicle?->id,
                'job_card_id' => ($ctx['job_card'] ?? null)?->id,
                'anchor_date' => $anchor->toDateString(),
                'next_step' => 0,
                'status' => JourneyEnrollment::ACTIVE,
            ]))->setRelation('journey', $journey);
            if (!$this->schedule($enrollment, $journey)) {
                continue;
            }
            $enrollment->unsetRelation('journey')->save();
            $count++;
        }

        // A message due right now goes out at once (e.g. the welcome on a new lead)
        if ($count) {
            $this->run(JourneyEnrollment::where('status', JourneyEnrollment::ACTIVE)->where('next_run_at', '<=', now())
                ->when($lead, fn ($q) => $q->where('lead_id', $lead->id), fn ($q) => $q->where('customer_id', $customer?->id))
                ->pluck('id')->all());
        }

        return $count;
    }

    /**
     * Point the enrolment at its next step still worth running. False when none is left.
     */
    protected function schedule(JourneyEnrollment $enrollment, Journey $journey): bool
    {
        $steps = $journey->steps->values();

        while ($enrollment->next_step < $steps->count()) {
            $step = $steps[$enrollment->next_step];
            $at = $this->stepTime($enrollment, $step);
            // A step more than two days in the past (enrolled late) is skipped
            if ($at->gte(now()->subDays(2))) {
                $enrollment->next_run_at = $at->lt(now()) ? now() : $at;

                return true;
            }
            $enrollment->next_step++;
        }

        $enrollment->status = JourneyEnrollment::DONE;
        $enrollment->next_run_at = null;

        return false;
    }

    protected function stepTime(JourneyEnrollment $enrollment, JourneyStep $step): Carbon
    {
        $at = $enrollment->anchor_date->copy()->addDays($step->day_offset);
        [$h, $m] = array_map('intval', explode(':', (string) $step->send_at));

        // Day 0 of a new lead runs straight away, not at 10:30
        if ($step->day_offset === 0 && $enrollment->anchor_date->isToday() && !in_array($enrollment->journey?->trigger, Journey::DUE_DATE_TRIGGERS, true)) {
            return now();
        }

        return $at->setTime($h, $m);
    }

    /**
     * Run due steps (all, or the given enrolments).
     *
     * @param array<int, int>|null $ids
     */
    public function run(?array $ids = null): int
    {
        $ran = 0;
        $query = JourneyEnrollment::with(['journey.steps.creative', 'lead.customer', 'customer', 'vehicle'])
            ->where('status', JourneyEnrollment::ACTIVE)
            ->where('next_run_at', '<=', now())
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))
            ->orderBy('next_run_at');

        foreach ($query->limit(500)->get() as $enrollment) {
            if ($this->runOne($enrollment)) {
                $ran++;
            }
        }

        return $ran;
    }

    protected function runOne(JourneyEnrollment $enrollment): bool
    {
        $journey = $enrollment->journey;
        $steps = $journey->steps->values();
        $step = $steps[$enrollment->next_step] ?? null;

        if (!$step || !$journey->is_active) {
            return $this->finish($enrollment, $step ? 'journey switched off' : null);
        }
        if ($reason = $this->stopReason($enrollment)) {
            return $this->finish($enrollment, $reason);
        }

        // Only in the sending window
        $start = Carbon::parse(today()->toDateString() . ' ' . WorkshopSettings::get('crm.quiet_start'));
        $end = Carbon::parse(today()->toDateString() . ' ' . WorkshopSettings::get('crm.quiet_end'));
        if (now()->lt($start) || now()->gte($end)) {
            $enrollment->update(['next_run_at' => now()->lt($start) ? $start : $start->copy()->addDay()]);

            return false;
        }

        // One marketing message per person every few days
        $marketing = in_array($journey->trigger, Journey::MARKETING_TRIGGERS, true);
        if ($step->action === 'message' && $marketing && $this->recentlyMessaged($enrollment)) {
            $enrollment->update(['next_run_at' => now()->addDay()->setTime(10, 30)]);

            return false;
        }

        match ($step->action) {
            'task' => $this->doTask($enrollment, $step),
            'lead' => $this->openLead($enrollment, $step),
            default => $this->sendMessage($enrollment, $step, $marketing),
        };

        $enrollment->next_step++;
        if (!$this->schedule($enrollment, $journey)) {
            $enrollment->status = JourneyEnrollment::DONE;
        }
        $enrollment->save();

        return true;
    }

    protected function sendMessage(JourneyEnrollment $enrollment, JourneyStep $step, bool $marketing): void
    {
        $creative = $step->creative;
        if (!$creative || !$creative->is_active) {
            return;
        }

        $messages = app(CrmMessageService::class);
        $params = ['due_date' => $enrollment->anchor_date->format('d M Y')];
        $options = ['marketing' => $marketing, 'enrollment_id' => $enrollment->id, 'job_card_id' => $enrollment->job_card_id, 'vehicle_id' => $enrollment->vehicle_id];
        $template = "crm.journey.{$enrollment->journey_id}";

        if ($enrollment->lead) {
            $messages->toLead($enrollment->lead, $template, $creative->caption, $params, $creative, null, $options);
        } elseif ($enrollment->customer) {
            $assignee = $enrollment->job_card_id ? JobCard::withoutGlobalScopes()->whereKey($enrollment->job_card_id)->value('service_advisor_id') : null;
            $messages->toCustomer($enrollment->customer, $enrollment->vehicle, $template, $creative->caption, $params, $creative, null, [...$options, 'assigned_to' => $assignee]);
        }
    }

    protected function doTask(JourneyEnrollment $enrollment, JourneyStep $step): void
    {
        if ($enrollment->lead) {
            $lead = $enrollment->lead;
            if ($lead->isOpen() || $enrollment->journey->trigger === 'lost_not_now') {
                $lead->update(['next_follow_up_at' => now()]);
                app(LeadService::class)->log($lead, 'note', null, 'Follow-up: ' . ($step->task_note ?: $enrollment->journey->name));
            }

            return;
        }

        $this->openLead($enrollment, $step);
    }

    /**
     * A lead for the executive about a customer's car (service due, pending work, win-back…).
     */
    protected function openLead(JourneyEnrollment $enrollment, JourneyStep $step): void
    {
        $customer = $enrollment->customer ?? $enrollment->lead?->customer;
        if (!$customer) {
            $enrollment->lead?->update(['next_follow_up_at' => now()]);

            return;
        }

        $vehicle = $enrollment->vehicle;
        app(LeadService::class)->capture([
            'name' => $customer->name,
            'phone' => $customer->whatsapp_phone ?: $customer->phone,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle?->id,
            'category_id' => $vehicle?->category_id,
            'registration_no' => $vehicle?->registration_no,
            'branch_id' => $enrollment->job_card_id ? JobCard::withoutGlobalScopes()->whereKey($enrollment->job_card_id)->value('branch_id') : null,
            'source' => LeadSource::bySlug('existing') ? 'existing' : null,
            'kind' => self::LEAD_KINDS[$enrollment->journey->trigger] ?? 'new_enquiry',
            'requirement' => $step->task_note ?: $enrollment->journey->name,
        ]);
    }

    /**
     * Why the journey should stop now, if it should.
     */
    protected function stopReason(JourneyEnrollment $e): ?string
    {
        $trigger = $e->journey->trigger;
        $lead = $e->lead;
        $customer = $e->customer ?? $lead?->customer;

        if ($lead?->opted_out_at || $customer?->marketing_opt_out_at) {
            if (in_array($trigger, Journey::MARKETING_TRIGGERS, true)) {
                return 'opted out';
            }
        }

        if ($lead) {
            if ($trigger === 'lost_not_now') {
                return $lead->status === Lead::LOST ? null : 'lead reopened';
            }
            if (in_array($trigger, ['new_lead', 'no_show'], true) && !in_array($lead->status, [Lead::NEW, Lead::CONTACTED, Lead::INTERESTED, Lead::UNREACHABLE], true)) {
                return 'lead ' . $lead->status;
            }
        }

        // Customer journeys about a car stop once the car is booked or serviced again
        if (in_array($trigger, ['service_due', 'pending_work', 'win_back', 'insurance_due'], true) && $customer) {
            $booked = Booking::where('customer_id', $customer->id)
                ->when($e->vehicle_id, fn ($q) => $q->where('vehicle_id', $e->vehicle_id))
                ->whereIn('status', [Booking::BOOKED, Booking::CONFIRMED, Booking::ARRIVED])
                ->exists();
            if ($booked) {
                return 'booked';
            }
            $since = $e->created_at ?? now();
            $visited = JobCard::withoutGlobalScopes()
                ->where('customer_id', $customer->id)
                ->when($e->vehicle_id, fn ($q) => $q->where('vehicle_id', $e->vehicle_id))
                ->where('created_at', '>', $since)
                ->where('status', '!=', 'cancelled')
                ->exists();
            if ($visited && $trigger !== 'insurance_due') {
                return 'visited';
            }
        }

        return null;
    }

    protected function recentlyMessaged(JourneyEnrollment $e): bool
    {
        $days = (int) WorkshopSettings::get('crm.frequency_cap_days');
        if ($days <= 0) {
            return false;
        }

        return CustomerMessage::where('purpose', 'crm')
            ->where('template', 'like', 'crm.journey.%')
            ->where('created_at', '>', now()->subDays($days))
            ->whereIn('status', [CustomerMessage::PREPARED, CustomerMessage::SENT])
            ->where(fn ($q) => $q->when($e->lead_id, fn ($q) => $q->where('lead_id', $e->lead_id))
                ->when($e->customer_id, fn ($q) => $q->orWhere('customer_id', $e->customer_id)))
            ->exists();
    }

    protected function finish(JourneyEnrollment $e, ?string $reason): bool
    {
        $e->update([
            'status' => $reason ? JourneyEnrollment::STOPPED : JourneyEnrollment::DONE,
            'stop_reason' => $reason,
            'next_run_at' => null,
        ]);

        return false;
    }

    /**
     * Stop a lead's journeys (it booked, was won / lost, or opted out). Messages queued
     * but not yet sent are dismissed.
     */
    public function stopFor(Lead $lead, string $reason): void
    {
        $ids = JourneyEnrollment::where('lead_id', $lead->id)
            ->where('status', JourneyEnrollment::ACTIVE)
            // Closing a lead as lost keeps (or starts) its "not now" journey
            ->when($reason === Lead::LOST, fn ($q) => $q->whereHas('journey', fn ($j) => $j->where('trigger', '!=', 'lost_not_now')))
            ->pluck('id');

        JourneyEnrollment::whereIn('id', $ids)->update(['status' => JourneyEnrollment::STOPPED, 'stop_reason' => $reason, 'next_run_at' => null]);
        CustomerMessage::whereIn('journey_enrollment_id', $ids)->where('status', CustomerMessage::PREPARED)->update(['status' => CustomerMessage::DISMISSED]);
    }

    /**
     * Stop a customer's car journeys of some triggers (e.g. booked again).
     */
    public function stopForCustomer(int $customerId, ?int $vehicleId, array $triggers, string $reason): void
    {
        $ids = JourneyEnrollment::where('customer_id', $customerId)
            ->when($vehicleId, fn ($q) => $q->where(fn ($q) => $q->where('vehicle_id', $vehicleId)->orWhereNull('vehicle_id')))
            ->where('status', JourneyEnrollment::ACTIVE)
            ->whereHas('journey', fn ($q) => $q->whereIn('trigger', $triggers))
            ->pluck('id');

        JourneyEnrollment::whereIn('id', $ids)->update(['status' => JourneyEnrollment::STOPPED, 'stop_reason' => $reason, 'next_run_at' => null]);
        CustomerMessage::whereIn('journey_enrollment_id', $ids)->where('status', CustomerMessage::PREPARED)->update(['status' => CustomerMessage::DISMISSED]);
    }
}
