<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Billing\Models\CustomerCredit;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\Feedback;
use App\Modules\Crm\Models\JourneyEnrollment;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Referral;
use App\Modules\Crm\Services\CrmInsightsService;
use App\Modules\Crm\Services\CrmMessageService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\CustomerMessage;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightsController extends ApiController
{
    public function __construct(protected CrmInsightsService $insights) {}

    public function summary(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->startOfMonth()->toDateString());
        $to = $request->query('to', today()->toDateString());

        return $this->success($this->insights->summary($from, $to, $request->integer('branch_id') ?: null));
    }

    public function tiles(Request $request): JsonResponse
    {
        return $this->success($this->insights->tiles($request->integer('branch_id') ?: null));
    }

    // -------------------------------------------------------------- feedback

    public function feedback(Request $request): JsonResponse
    {
        $rows = Feedback::with(['customer:id,name,phone', 'jobCard:id,job_number,service_advisor_id', 'resolvedBy:id,name'])
            ->when($request->query('filter') === 'low', fn ($q) => $q->where('rating', '<=', 3))
            ->when($request->query('filter') === 'open', fn ($q) => $q->where('rating', '<=', 3)->whereNull('resolved_at'))
            ->latest('id')
            ->limit(200)
            ->get();

        return $this->success([
            'average' => round((float) Feedback::where('created_at', '>=', today()->subDays(90))->avg('rating'), 1),
            'rows' => $rows->map(fn (Feedback $f) => [
                'id' => $f->id,
                'rating' => $f->rating,
                'comments' => $f->comments,
                'customer' => $f->customer?->name,
                'phone' => $f->customer?->phone,
                'customer_id' => $f->customer_id,
                'job_card_id' => $f->job_card_id,
                'job_number' => $f->jobCard?->job_number,
                'review_clicked' => (bool) $f->review_clicked_at,
                'resolved_at' => $f->resolved_at?->toIso8601String(),
                'resolved_by' => $f->resolvedBy?->name,
                'resolution_note' => $f->resolution_note,
                'at' => $f->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function resolveFeedback(Request $request, Feedback $feedback): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|max:500']);
        $feedback->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id, 'resolution_note' => $data['note']]);

        return $this->success(null, 'Marked as resolved');
    }

    // ---------------------------------------------------------- customer 360

    /**
     * Everything about a customer on one timeline.
     */
    public function customer(Customer $customer, CrmMessageService $messages): JsonResponse
    {
        $phone10 = substr(preg_replace('/\D+/', '', (string) $customer->phone), -10);
        $leads = Lead::with(['source:id,name', 'assignee:id,name'])
            ->where(fn ($q) => $q->where('customer_id', $customer->id)->when(strlen($phone10) === 10, fn ($q) => $q->orWhere('phone', 'like', "%{$phone10}")))
            ->latest('id')->get();
        $jobs = JobCard::withoutGlobalScopes()->with('vehicle:id,registration_no')->where('customer_id', $customer->id)->latest('id')->get(['id', 'job_number', 'status', 'vehicle_id', 'created_at']);
        $bills = Invoice::withoutGlobalScopes()->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('kind', 'tax')->whereIn('status', ['issued', 'partial', 'paid']))->orWhere(fn ($q) => $q->where('kind', 'proforma')->where('status', 'closed')))
            ->get(['id', 'invoice_number', 'invoice_date', 'total_amount', 'job_card_id']);
        $bookings = Booking::where('customer_id', $customer->id)->orWhereIn('lead_id', $leads->pluck('id'))->latest('id')->get();
        $feedback = Feedback::where('customer_id', $customer->id)->get();
        $msgs = CustomerMessage::where('customer_id', $customer->id)->orWhereIn('lead_id', $leads->pluck('id'))->latest('id')->limit(60)->get();

        $timeline = collect()
            ->merge($leads->map(fn ($l) => ['at' => $l->created_at, 'type' => 'lead', 'title' => 'Enquiry — ' . ($l->source?->name ?? 'lead'), 'detail' => Lead::STATUSES[$l->status] ?? $l->status, 'to' => "/crm/leads/{$l->id}"]))
            ->merge($bookings->map(fn ($b) => ['at' => $b->created_at, 'type' => 'booking', 'title' => "Booking {$b->number} for " . $b->booking_date->format('d M Y'), 'detail' => Booking::STATUSES[$b->status] ?? $b->status, 'to' => null]))
            ->merge($jobs->map(fn ($j) => ['at' => $j->created_at, 'type' => 'job', 'title' => "Job {$j->job_number}" . ($j->vehicle ? " · {$j->vehicle->registration_no}" : ''), 'detail' => str_replace('_', ' ', $j->status), 'to' => "/job-cards/{$j->id}"]))
            ->merge($bills->map(fn ($i) => ['at' => $i->invoice_date, 'type' => 'bill', 'title' => "Bill {$i->invoice_number}", 'detail' => 'Rs ' . number_format((float) $i->total_amount, 0), 'to' => $i->job_card_id ? "/job-cards/{$i->job_card_id}" : null]))
            ->merge($feedback->map(fn ($f) => ['at' => $f->created_at, 'type' => 'feedback', 'title' => "Rated {$f->rating}★", 'detail' => $f->comments, 'to' => null]))
            ->merge($msgs->map(fn ($m) => ['at' => $m->sent_at ?? $m->created_at, 'type' => 'message', 'title' => 'WhatsApp' . ($m->status === 'sent' ? '' : " ({$m->status})"), 'detail' => mb_strimwidth($m->body, 0, 140, '…'), 'to' => null]))
            ->sortByDesc(fn ($e) => (string) $e['at'])
            ->map(fn ($e) => [...$e, 'at' => $e['at'] ? \Carbon\Carbon::parse($e['at'])->toIso8601String() : null])
            ->values();

        $vehicles = Vehicle::where('customer_id', $customer->id)->get(['id', 'registration_no', 'next_service_due_date', 'next_service_due_km', 'last_service_at', 'insurance_expiry']);

        return $this->success([
            'summary' => [
                'visits' => $jobs->where('status', 'delivered')->count(),
                'spend' => round((float) $bills->sum('total_amount'), 2),
                'last_visit' => $jobs->where('status', 'delivered')->first()?->created_at?->toDateString(),
                'avg_rating' => $feedback->isNotEmpty() ? round((float) $feedback->avg('rating'), 1) : null,
                'credit' => CustomerCredit::balanceFor($customer->id),
                'referrals' => Referral::where('referrer_customer_id', $customer->id)->count(),
                'referral_code' => $customer->referral_code,
                'opted_out' => (bool) $customer->marketing_opt_out_at,
                'open_lead_id' => $leads->first(fn ($l) => $l->isOpen())?->id,
                'active_journeys' => JourneyEnrollment::with('journey:id,name')->where('customer_id', $customer->id)->where('status', 'active')->get()->map(fn ($e) => $e->journey?->name)->filter()->values(),
            ],
            'vehicles' => $vehicles->map(fn ($v) => [
                'id' => $v->id,
                'registration_no' => $v->registration_no,
                'next_service_due_date' => $v->next_service_due_date?->toDateString(),
                'next_service_due_km' => $v->next_service_due_km,
                'last_service_at' => $v->last_service_at?->toDateString(),
                'insurance_expiry' => $v->insurance_expiry?->toDateString(),
            ]),
            'timeline' => $timeline,
        ]);
    }
}
