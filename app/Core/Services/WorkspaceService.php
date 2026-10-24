<?php

namespace App\Core\Services;

use App\Core\Models\User;
use App\Core\Support\Modules;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\Feedback;
use App\Modules\Crm\Models\Lead;
use App\Modules\Hr\Models\AttendancePunch;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\Vehicle;

/**
 * Across modules: one search box for any record, and "My Day" — what each person should
 * do now, by what they are allowed to do.
 */
class WorkspaceService
{
    /**
     * Phone, car number, job / invoice / booking number, or a name.
     *
     * @return array<int, array{type: string, label: string, sub: ?string, to: string}>
     */
    public function search(User $user, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $digits = preg_replace('/\D+/', '', $q);
        $compact = strtoupper(preg_replace('/\s+/', '', $q));
        $like = "%{$q}%";
        $out = [];

        if ($user->hasPermission('workshop.customers.view')) {
            Customer::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%")->orWhere('whatsapp_phone', 'like', "%{$digits}%")))
                ->limit(6)->get(['id', 'name', 'phone'])
                ->each(function ($c) use (&$out) {
                    $out[] = ['type' => 'customer', 'label' => $c->name, 'sub' => $c->phone, 'to' => "/customers/{$c->id}"];
                });
        }
        if ($user->hasPermission('workshop.vehicles.view') && strlen($compact) >= 3) {
            Vehicle::with('customer:id,name')
                ->whereRaw("REPLACE(UPPER(registration_no), ' ', '') LIKE ?", ["%{$compact}%"])
                ->limit(6)->get(['id', 'registration_no', 'customer_id'])
                ->each(function ($v) use (&$out) {
                    $out[] = ['type' => 'vehicle', 'label' => $v->registration_no, 'sub' => $v->customer?->name, 'to' => $v->customer_id ? "/customers/{$v->customer_id}" : "/vehicles/{$v->id}/edit"];
                });
        }
        if ($user->hasPermission('workshop.job-cards.view')) {
            JobCard::with(['customer:id,name', 'vehicle:id,registration_no'])
                ->where('job_number', 'like', $like)
                ->latest('id')->limit(6)->get(['id', 'job_number', 'customer_id', 'vehicle_id', 'status'])
                ->each(function ($j) use (&$out) {
                    $out[] = ['type' => 'job', 'label' => $j->job_number, 'sub' => trim(($j->vehicle?->registration_no ?? '') . ' · ' . ($j->customer?->name ?? ''), ' ·'), 'to' => "/job-cards/{$j->id}"];
                });
        }
        if ($user->hasPermission('billing.invoices.view')) {
            Invoice::with('customer:id,name')
                ->where('invoice_number', 'like', $like)
                ->latest('id')->limit(5)->get(['id', 'invoice_number', 'customer_id', 'job_card_id'])
                ->each(function ($i) use (&$out) {
                    $out[] = ['type' => 'invoice', 'label' => $i->invoice_number, 'sub' => $i->customer?->name, 'to' => $i->job_card_id ? "/job-cards/{$i->job_card_id}" : "/invoices/{$i->id}"];
                });
        }
        if (Modules::crm() && $user->hasPermission('crm.leads.view')) {
            Lead::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}%"))->orWhere('registration_no', 'like', "%{$compact}%"))
                ->latest('id')->limit(6)->get(['id', 'name', 'phone', 'status'])
                ->each(function ($l) use (&$out) {
                    $out[] = ['type' => 'lead', 'label' => $l->name, 'sub' => $l->phone . ' · ' . (Lead::STATUSES[$l->status] ?? $l->status), 'to' => "/crm/leads/{$l->id}"];
                });
            Booking::where('number', 'like', $like)->limit(4)->get(['id', 'number', 'booking_date'])
                ->each(function ($b) use (&$out) {
                    $out[] = ['type' => 'booking', 'label' => $b->number, 'sub' => $b->booking_date->format('d M'), 'to' => "/crm/bookings?date={$b->booking_date->toDateString()}"];
                });
        }

        return $out;
    }

    /**
     * What this person should do today.
     */
    public function myDay(User $user): array
    {
        $today = today()->toDateString();
        $cards = [];

        if (Modules::hr() && $user->hasPermission('hr.self')) {
            $last = AttendancePunch::valid()->where('user_id', $user->id)->where('work_date', $today)->latest('punched_at')->first();
            $cards[] = [
                'key' => 'attendance',
                'title' => $last?->type === 'in' ? 'You are in since ' . $last->punched_at->format('H:i') : ($last ? 'You are out' : 'Punch in'),
                'count' => null,
                'tone' => $last ? 'ok' : 'action',
                'to' => '/attendance',
            ];
        }

        if (Modules::crm() && $user->hasPermission('crm.leads.work')) {
            $mine = Lead::open()->where('assigned_to', $user->id);
            $notContacted = (clone $mine)->where('status', Lead::NEW)->whereNull('first_contacted_at')->count();
            $due = (clone $mine)->where('next_follow_up_at', '<=', today()->endOfDay())->count();
            $cards[] = ['key' => 'new_leads', 'title' => 'New leads to call', 'count' => $notContacted, 'tone' => $notContacted ? 'urgent' : 'ok', 'to' => '/crm/leads?view=today'];
            $cards[] = ['key' => 'follow_ups', 'title' => 'Follow-ups due today', 'count' => $due, 'tone' => $due ? 'action' : 'ok', 'to' => '/crm/leads?view=today'];
        }
        if (Modules::crm() && $user->hasPermission('crm.messages.send')) {
            $waiting = CustomerMessage::where('purpose', 'crm')->where('status', CustomerMessage::PREPARED)->where('scheduled_at', '<=', now())
                ->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhereNull('assigned_to'))->count();
            $cards[] = ['key' => 'messages', 'title' => 'WhatsApp messages to send', 'count' => $waiting, 'tone' => $waiting ? 'action' : 'ok', 'to' => '/crm/messages'];
        }
        if (Modules::crm() && $user->hasPermission('crm.bookings.manage')) {
            $bookings = Booking::whereDate('booking_date', $today)->whereIn('status', [Booking::BOOKED, Booking::CONFIRMED])->count();
            $toConfirm = Booking::where('status', Booking::BOOKED)->where('booked_via', '!=', 'staff')->count();
            $cards[] = ['key' => 'arrivals', 'title' => 'Cars booked today, not arrived yet', 'count' => $bookings, 'tone' => $bookings ? 'action' : 'ok', 'to' => '/crm/bookings'];
            if ($toConfirm) {
                $cards[] = ['key' => 'confirm', 'title' => 'Online bookings to confirm', 'count' => $toConfirm, 'tone' => 'urgent', 'to' => '/crm/bookings?status=booked'];
            }
        }

        if ($user->hasAnyRole(['technician', 'body-shop-technician'])) {
            $jobs = JobCard::where(fn ($q) => $q->where('technician_id', $user->id)->orWhereHas('technicians', fn ($t) => $t->where('users.id', $user->id)))
                ->whereNotIn('status', [JobCardStatus::Delivered->value, JobCardStatus::Cancelled->value, JobCardStatus::GatePassGenerated->value])
                ->count();
            $cards[] = ['key' => 'my_jobs', 'title' => 'My open jobs', 'count' => $jobs, 'tone' => $jobs ? 'action' : 'ok', 'to' => '/my-jobs'];
        }
        if ($user->hasPermission('workshop.trips.drive')) {
            $trips = JobTrip::where('assigned_to', $user->id)->whereDate('slot_date', $today)->whereIn('status', [JobTrip::SCHEDULED, JobTrip::EN_ROUTE, JobTrip::ARRIVED])->count();
            $cards[] = ['key' => 'trips', 'title' => 'My trips today', 'count' => $trips, 'tone' => $trips ? 'action' : 'ok', 'to' => '/trips'];
        }
        if ($user->hasPermission('workshop.job-cards.update') && !$user->hasAnyRole(['technician', 'body-shop-technician'])) {
            $approval = JobCard::where('status', JobCardStatus::AwaitingApproval->value)->when(!$user->hasPermission('workshop.job-cards.override-guards'), fn ($q) => $q->where('service_advisor_id', $user->id))->count();
            $ready = JobCard::where('status', JobCardStatus::ReadyForDelivery->value)->count();
            $cards[] = ['key' => 'approval', 'title' => 'Jobs waiting for customer approval', 'count' => $approval, 'tone' => $approval ? 'action' : 'ok', 'to' => '/job-cards?status=awaiting_approval'];
            $cards[] = ['key' => 'ready', 'title' => 'Ready for delivery (bill & hand over)', 'count' => $ready, 'tone' => $ready ? 'action' : 'ok', 'to' => '/job-cards?status=ready_for_delivery'];
        }
        // Back office: jobs the advisor sent for the final bill that have no bill yet
        if ($user->hasRole('backoffice') && $user->hasPermission('billing.proforma.manage')) {
            $bills = JobCard::where('status', JobCardStatus::ReadyForDelivery->value)->whereNotNull('billing_requested_at')
                ->whereNotIn('id', Invoice::withoutGlobalScopes()->whereNotNull('job_card_id')->where('status', '!=', 'cancelled')->select('job_card_id'))
                ->count();
            $cards[] = ['key' => 'bills', 'title' => 'Bills to make (sent by advisors)', 'count' => $bills, 'tone' => $bills ? 'action' : 'ok', 'to' => '/job-cards?status=ready_for_delivery'];
        }
        if (Modules::hr() && $user->hasPermission('hr.leave.approve')) {
            $leave = LeaveRequest::where('status', LeaveRequest::PENDING)->when(!$user->isOwner(), fn ($q) => $q->whereIn('branch_id', $user->getAccessibleBranchIds()))->count();
            if ($leave) {
                $cards[] = ['key' => 'leave', 'title' => 'Leave requests to answer', 'count' => $leave, 'tone' => 'action', 'to' => '/hr/leave'];
            }
        }
        if (Modules::crm() && $user->hasPermission('crm.reports.view')) {
            $low = Feedback::where('rating', '<=', 3)->whereNull('resolved_at')->count();
            if ($low) {
                $cards[] = ['key' => 'feedback', 'title' => 'Unhappy customers to call back', 'count' => $low, 'tone' => 'urgent', 'to' => '/crm/feedback'];
            }
        }

        return ['date' => $today, 'cards' => $cards];
    }
}
