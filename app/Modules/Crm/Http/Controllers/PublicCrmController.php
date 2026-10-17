<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\Branch;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\CrmLink;
use App\Modules\Crm\Models\Feedback;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadSource;
use App\Modules\Crm\Services\BookingService;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\Crm\Support\LeadPayload;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\OilType;
use App\Modules\Workshop\Models\ServicePackage;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * No login: leads posted by ad platforms / connectors / the website, and the customer's
 * CRM link pages (book a slot, rate the service, refer a friend, view a creative, stop
 * messages). A token or a source key is the credential.
 */
class PublicCrmController extends ApiController
{
    // ----------------------------------------------------------------- intake

    public function webhook(Request $request, string $key, LeadService $leads): JsonResponse
    {
        // Meta's subscription check echoes the challenge
        if ($request->isMethod('get')) {
            return response()->json((int) $request->query('hub_challenge', 0));
        }

        $source = LeadSource::where('webhook_key', $key)->where('is_active', true)->first();
        abort_unless($source, 404);

        $data = LeadPayload::parse($request->all());
        if (empty($data['phone'])) {
            return $this->error('No phone number in the lead.', 422);
        }

        [$lead, $new] = $leads->capture([...$data, 'lead_source_id' => $source->id]);

        return $this->success(['lead_id' => $lead->id, 'new' => $new], $new ? 'Lead received' : 'Existing lead updated');
    }

    // ------------------------------------------------------------- link pages

    protected function link(string $token): CrmLink
    {
        $link = strlen($token) === 32 ? CrmLink::with(['lead.customer', 'creative'])->where('token_hash', CrmLink::hashToken($token))->first() : null;
        abort_unless($link && $link->isUsable(), 404, 'This link has expired. Message us and we will send a new one.');

        return $link;
    }

    public function show(string $token, LeadService $leads, BookingService $bookings): JsonResponse
    {
        $link = $this->link($token);
        $first = !$link->first_opened_at;
        $link->update(['first_opened_at' => $link->first_opened_at ?? now(), 'opens' => $link->opens + 1]);

        if ($link->lead && $first) {
            $leads->log($link->lead, 'link_opened', null, ucfirst($link->purpose) . ' link opened');
            $leads->bumpScore($link->lead, $link->purpose === CrmLink::BOOK ? 15 : 5);
        }

        $customer = $link->customer_id ? Customer::find($link->customer_id) : $link->lead?->customer;
        $branch = Branch::with('organization')->find($link->branch_id ?? $link->lead?->branch_id) ?? Branch::active()->with('organization')->first();
        $base = [
            'purpose' => $link->purpose,
            'workshop' => $branch?->organization?->name ?? config('app.name'),
            'branch' => $branch ? ['name' => $branch->name, 'address' => trim(($branch->address ?? '') . ' ' . ($branch->city ?? '')), 'phone' => $branch->phone] : null,
            'name' => $customer?->name ?? $link->lead?->name,
        ];

        return $this->success([...$base, ...match ($link->purpose) {
            CrmLink::BOOK => $this->bookPage($link, $bookings),
            CrmLink::FEEDBACK => $this->feedbackPage($link),
            CrmLink::REFERRAL => ['referrer' => $customer ? explode(' ', $customer->name)[0] : null],
            default => $this->creativePage($link, $token),
        }]);
    }

    protected function bookPage(CrmLink $link, BookingService $bookings): array
    {
        $lead = $link->lead;
        $vehicle = $link->vehicle_id ? Vehicle::find($link->vehicle_id) : $lead?->vehicle;
        $categoryId = $vehicle?->category_id ?? $lead?->category_id;
        $branchId = (int) ($link->branch_id ?? $lead?->branch_id ?? Branch::active()->value('id'));
        $days = (int) WorkshopSettings::get('crm.booking_days_ahead');

        $calendar = [];
        for ($i = 0; $i < $days; $i++) {
            $date = today()->addDays($i)->toDateString();
            $free = $bookings->availability($branchId, $date)->sum('free');
            $calendar[] = ['date' => $date, 'free' => $free];
        }

        $oils = OilType::pluck('name', 'id');
        $plans = ServicePackage::with('pricing')->where('is_active', true)->orderBy('sort_order')->get()->map(function (ServicePackage $p) use ($categoryId, $oils) {
            $rows = $p->pricing->filter(fn ($r) => !$categoryId || (int) $r->category_id === (int) $categoryId);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'home_service' => (bool) $p->home_service_available,
                'from_price' => $rows->isNotEmpty() ? (float) $rows->min('price') : null,
                'features' => array_slice((array) ($p->features ?? []), 0, 8),
                'oils' => $categoryId ? $rows->sortBy('price')->map(fn ($r) => ['oil_type_id' => $r->oil_type_id, 'name' => $oils[$r->oil_type_id] ?? 'Oil', 'price' => (float) $r->price])->values() : [],
            ];
        })->filter(fn ($p) => $p['from_price'] !== null)->values();

        $upcoming = Booking::where(fn ($q) => $q->when($lead, fn ($q) => $q->where('lead_id', $lead->id))->when($link->customer_id, fn ($q) => $q->orWhere('customer_id', $link->customer_id)))
            ->whereIn('status', [Booking::BOOKED, Booking::CONFIRMED])
            ->whereDate('booking_date', '>=', today())
            ->first();

        return [
            'car' => $vehicle?->registration_no ?? $lead?->carLabel(),
            'package_id' => $link->package_id ?? $lead?->package_id,
            'plans' => $plans,
            'calendar' => $calendar,
            'modes' => ['drive_in' => 'I will bring the car', 'pickup_drop' => 'Pick up & drop my car', 'home' => 'Service at my home'],
            'upcoming' => $upcoming ? ['number' => $upcoming->number, 'date' => $upcoming->booking_date->toDateString(), 'slot' => substr((string) $upcoming->slot_from, 0, 5), 'mode' => $upcoming->service_mode] : null,
        ];
    }

    protected function feedbackPage(CrmLink $link): array
    {
        $job = $link->job_card_id ? JobCard::withoutGlobalScopes()->with('vehicle:id,registration_no')->find($link->job_card_id) : null;
        $existing = $job ? Feedback::where('job_card_id', $job->id)->first() : null;

        return [
            'job_number' => $job?->job_number,
            'car' => $job?->vehicle?->registration_no,
            'rated' => $existing?->rating,
        ];
    }

    protected function creativePage(CrmLink $link, string $token): array
    {
        $c = $link->creative;

        return [
            'title' => $c?->title,
            'kind' => $c?->kind,
            'caption' => $c?->caption ? preg_replace('/:\w+/', '', $c->caption) : null,
            'media_url' => $c?->hasFile() ? url("/api/v1/public/c/{$token}/media") : null,
            'book_url' => null,
        ];
    }

    public function slots(Request $request, string $token, BookingService $bookings): JsonResponse
    {
        $link = $this->link($token);
        $request->validate(['date' => 'required|date']);
        $branchId = (int) ($link->branch_id ?? $link->lead?->branch_id ?? Branch::active()->value('id'));

        return $this->success($bookings->availability($branchId, $request->query('date'))->map(fn ($s) => ['from' => $s['from'], 'to' => $s['to'], 'free' => $s['free']])->values());
    }

    public function book(Request $request, string $token, BookingService $bookings): JsonResponse
    {
        $link = $this->link($token);
        abort_unless($link->purpose === CrmLink::BOOK, 404);

        $data = $request->validate([
            'booking_date' => 'required|date|after_or_equal:today',
            'slot_from' => 'nullable|date_format:H:i',
            'package_id' => 'nullable|integer|exists:service_packages,id',
            'oil_type_id' => 'nullable|integer|exists:oil_types,id',
            'service_mode' => 'required|in:drive_in,pickup_drop,home',
            'address' => 'required_unless:service_mode,drive_in|nullable|string|max:1000',
            'landmark' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ], ['address.required_unless' => 'Enter the address.']);

        $booking = $bookings->create([
            ...$data,
            'lead_id' => $link->lead_id,
            'customer_id' => $link->customer_id ?? $link->lead?->customer_id,
            'vehicle_id' => $link->vehicle_id ?? $link->lead?->vehicle_id,
            'branch_id' => $link->branch_id ?? $link->lead?->branch_id,
            'service_type' => !empty($data['package_id']) ? 'scheduled' : null,
        ], null, 'self');

        return $this->created([
            'number' => $booking->number,
            'date' => $booking->booking_date->toDateString(),
            'slot' => $booking->slot_from ? substr($booking->slot_from, 0, 5) : null,
        ], 'Booked! We will call you to confirm.');
    }

    public function feedback(Request $request, string $token, RetentionService $retention): JsonResponse
    {
        $link = $this->link($token);
        abort_unless($link->purpose === CrmLink::FEEDBACK, 404);
        $data = $request->validate(['rating' => 'required|integer|between:1,5', 'comments' => 'nullable|string|max:1000']);

        $result = $retention->submitFeedback($link, (int) $data['rating'], $data['comments'] ?? null);

        return $this->success(['review_url' => $result['review_url']], 'Thank you for your feedback!');
    }

    public function reviewClicked(string $token): JsonResponse
    {
        $link = $this->link($token);
        if ($link->job_card_id) {
            Feedback::where('job_card_id', $link->job_card_id)->whereNull('review_clicked_at')->update(['review_clicked_at' => now()]);
        }

        return $this->success(null);
    }

    /**
     * A friend referred from a customer's link becomes a lead tagged with the referrer.
     */
    public function refer(Request $request, string $token, LeadService $leads): JsonResponse
    {
        $link = $this->link($token);
        abort_unless($link->purpose === CrmLink::REFERRAL && $link->customer_id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'car_text' => 'nullable|string|max:120',
            'requirement' => 'nullable|string|max:500',
        ]);

        $leads->capture([...$data, 'source' => 'referral', 'referred_by_customer_id' => $link->customer_id, 'branch_id' => $link->branch_id]);

        return $this->created(null, 'Thank you! We will contact your friend.');
    }

    public function stop(string $token, LeadService $leads): JsonResponse
    {
        $link = $this->link($token);
        if ($link->lead) {
            $leads->optOut($link->lead, null);
        } elseif ($link->customer_id) {
            Customer::whereKey($link->customer_id)->update(['marketing_opt_out_at' => now()]);
            app(\App\Modules\Crm\Services\JourneyService::class)->stopForCustomer($link->customer_id, null, \App\Modules\Crm\Models\Journey::MARKETING_TRIGGERS, 'opted out');
        }

        return $this->success(null, 'Done — we will not send you offers any more.');
    }

    public function media(string $token)
    {
        $link = $this->link($token);
        $creative = $link->creative;
        abort_unless($creative?->hasFile(), 404);
        $disk = Storage::disk($creative->file_disk ?: 'job_media');
        abort_unless($disk->exists($creative->file_path), 404);

        return response()->file($disk->path($creative->file_path), ['Content-Type' => $creative->mime ?: 'application/octet-stream', 'Cache-Control' => 'public, max-age=86400']);
    }
}
