<?php

namespace App\Modules\Crm\Services;

use App\Core\Models\Branch;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Crm\Models\Booking;
use App\Modules\Crm\Models\BookingSlot;
use App\Modules\Crm\Models\Lead;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Modules\Workshop\Services\JobCardService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Appointments, from slot to job card:
 *  - slots per weekday and branch with a car capacity (no slots on the branch's weekly off
 *    or holidays);
 *  - booked by staff, or by the customer from their link;
 *  - pickup / home bookings open the job card with its trip when confirmed (the driver's
 *    pickup is the intake); drive-ins open it when the car arrives, pre-filled;
 *  - a car that does not come is a no-show and goes back to nurturing.
 */
class BookingService
{
    public function __construct(
        protected CrmMessageService $messages,
        protected LeadService $leads,
        protected JourneyService $journeys,
    ) {}

    // ----------------------------------------------------------------- slots

    /**
     * @return Collection<int, array{from: string, to: string, capacity: int, booked: int, free: int}>
     */
    public function availability(int $branchId, string $date, ?int $ignoreBookingId = null): Collection
    {
        $day = Carbon::parse($date);
        $branch = Branch::find($branchId);
        if ($day->lt(today()) || !$branch) {
            return collect();
        }
        if ($day->dayOfWeek === (int) ($branch->weekly_off_day ?? -1) || Holiday::forBranch($branchId)->whereDate('date', $day)->exists()) {
            return collect();
        }

        $slots = $this->slotsFor($branchId, $day->dayOfWeek);
        // A working day with no slots of its own (e.g. Sunday when the weekly off is Friday) runs on Monday's
        if ($slots->isEmpty() && $day->dayOfWeek !== Carbon::MONDAY) {
            $slots = $this->slotsFor($branchId, Carbon::MONDAY);
        }

        $booked = Booking::where('branch_id', $branchId)
            ->whereDate('booking_date', $day)
            ->whereIn('status', Booking::HOLDING)
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->get(['slot_from'])
            ->countBy(fn ($b) => substr((string) $b->slot_from, 0, 5));

        return $slots->map(function (BookingSlot $s) use ($booked, $day) {
            $from = substr($s->start_time, 0, 5);
            $taken = (int) ($booked[$from] ?? 0);
            $past = $day->isToday() && Carbon::parse($day->toDateString() . ' ' . $s->end_time)->isPast();

            return [
                'from' => $from,
                'to' => substr($s->end_time, 0, 5),
                'capacity' => $s->capacity,
                'booked' => $taken,
                'free' => $past ? 0 : max(0, $s->capacity - $taken),
            ];
        })->values();
    }

    /**
     * The branch's own slots for a weekday, else the slots for every branch.
     */
    protected function slotsFor(int $branchId, int $weekday): Collection
    {
        $slots = BookingSlot::where('is_active', true)->where('weekday', $weekday)->where('branch_id', $branchId)->orderBy('start_time')->get();

        return $slots->isNotEmpty()
            ? $slots
            : BookingSlot::where('is_active', true)->where('weekday', $weekday)->whereNull('branch_id')->orderBy('start_time')->get();
    }

    // ---------------------------------------------------------------- create

    /**
     * @param array{lead_id?: ?int, customer_id?: ?int, vehicle_id?: ?int, branch_id?: ?int, booking_date: string, slot_from?: ?string,
     *   service_type?: ?string, package_id?: ?int, oil_type_id?: ?int, service_mode?: ?string, address?: ?string, landmark?: ?string,
     *   maps_url?: ?string, notes?: ?string} $data
     */
    public function create(array $data, ?User $user, string $via = 'staff'): Booking
    {
        $lead = !empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;
        $customer = !empty($data['customer_id']) ? Customer::find($data['customer_id']) : $lead?->customer;
        $vehicle = !empty($data['vehicle_id']) ? Vehicle::find($data['vehicle_id']) : $lead?->vehicle;
        if (!$lead && !$customer) {
            throw new CrmException('Choose the lead or customer.');
        }

        $branchId = (int) ($data['branch_id'] ?? $lead?->branch_id ?? app('branch.context')->getCurrentBranchId());
        $mode = $data['service_mode'] ?? JobCard::MODE_DRIVE_IN;
        if (!in_array($mode, JobCard::MODES, true)) {
            throw new CrmException('Choose drive-in, pickup & drop or home service.');
        }
        if ($mode !== JobCard::MODE_DRIVE_IN && !trim((string) ($data['address'] ?? ''))) {
            throw new CrmException('Enter the address for the ' . ($mode === JobCard::MODE_HOME ? 'home visit' : 'pickup') . '.', ['address' => ['Required.']]);
        }

        $slot = $this->checkSlot($branchId, $data['booking_date'], $data['slot_from'] ?? null);
        $serviceType = $data['service_type'] ?? ($data['package_id'] ?? $lead?->package_id ? 'scheduled' : ($lead?->interest && $lead->interest !== 'insurance' ? $lead->interest : 'general_repair'));

        $booking = DB::transaction(function () use ($data, $user, $via, $lead, $customer, $vehicle, $branchId, $mode, $slot, $serviceType) {
            $booking = Booking::create([
                'branch_id' => $branchId,
                'number' => Booking::generateNumber(),
                'lead_id' => $lead?->id,
                'customer_id' => $customer?->id,
                'vehicle_id' => $vehicle?->id,
                'booking_date' => $data['booking_date'],
                'slot_from' => $slot['from'] ?? null,
                'slot_to' => $slot['to'] ?? null,
                'service_type' => $serviceType,
                'package_id' => $serviceType === 'scheduled' ? ($data['package_id'] ?? $lead?->package_id) : null,
                'oil_type_id' => $serviceType === 'scheduled' ? ($data['oil_type_id'] ?? null) : null,
                'service_mode' => $mode,
                'address' => $data['address'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'maps_url' => $data['maps_url'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => Booking::BOOKED,
                'booked_via' => $via,
                'created_by' => $user?->id,
            ]);

            if ($lead) {
                $lead->first_contacted_at ??= now();
                $lead->next_follow_up_at = null;
                $lead->save();
                $this->leads->setStatus($lead, Lead::BOOKED, $user);
                $this->leads->log($lead, 'booking', null, "Booked {$booking->number} for " . $booking->booking_date->format('d M') . ($booking->slot_from ? ', ' . substr($booking->slot_from, 0, 5) : '') . ($via === 'self' ? ' (by the customer)' : ''), $user);
                $this->leads->bumpScore($lead, 20);
            }
            if ($customer) {
                $this->journeys->stopForCustomer($customer->id, $vehicle?->id, ['service_due', 'pending_work', 'win_back'], 'booked');
            }

            return $booking;
        });

        // Staff bookings are confirmed straight away; the customer's own wait for a call
        if ($via === 'staff') {
            $booking = $this->confirm($booking, $user);
        } else {
            $assignee = $lead?->assigned_to ? User::find($lead->assigned_to) : null;
            $alert = new StaffAlert('booking_self', "Booked online — {$booking->number}", ($lead?->name ?? $customer?->name) . ' booked ' . $booking->booking_date->format('d M') . ($booking->slot_from ? ' ' . substr($booking->slot_from, 0, 5) : '') . '. Call to confirm.', '/crm/bookings');
            ($assignee ? collect([$assignee]) : $this->bookingDesk($branchId))->each->notify($alert);
            $this->sendConfirmation($booking);
        }

        return $booking->fresh();
    }

    /**
     * @return array{from: string, to: string}|null
     */
    protected function checkSlot(int $branchId, string $date, ?string $from, ?int $ignore = null): ?array
    {
        if (Carbon::parse($date)->lt(today())) {
            throw new CrmException('Choose today or a later date.', ['booking_date' => ['In the past.']]);
        }
        $slots = $this->availability($branchId, $date, $ignore);
        if (!$from) {
            if ($slots->isEmpty()) {
                return null;
            }
            throw new CrmException('Choose a time slot.', ['slot_from' => ['Required.']]);
        }

        $slot = $slots->firstWhere('from', substr($from, 0, 5));
        if (!$slot) {
            throw new CrmException('That time is not a booking slot on this day.', ['slot_from' => ['Not available.']]);
        }
        if ($slot['free'] < 1) {
            throw new CrmException('That slot is full. Choose another time.', ['slot_from' => ['Full.']]);
        }

        return $slot;
    }

    /**
     * Confirmed: the customer is told; a pickup / home visit opens its job card and trip.
     */
    public function confirm(Booking $booking, ?User $user): Booking
    {
        if (!in_array($booking->status, [Booking::BOOKED, Booking::CONFIRMED], true)) {
            throw new CrmException('This booking can no longer be confirmed.');
        }

        $booking->update(['status' => Booking::CONFIRMED, 'confirmed_at' => $booking->confirmed_at ?? now()]);

        if ($booking->service_mode !== JobCard::MODE_DRIVE_IN && !$booking->job_card_id && $user) {
            $this->openJobCard($booking, $user);
            $booking->refresh();
        }

        // A customer's own booking was confirmed to them when they made it; a pickup / home
        // visit gets the job's own "scheduled" stage message instead
        if ($booking->booked_via === 'staff' && $booking->service_mode === JobCard::MODE_DRIVE_IN) {
            $this->sendConfirmation($booking);
        }

        return $booking->fresh();
    }

    public function reschedule(Booking $booking, string $date, ?string $from, User $user): Booking
    {
        if (!$booking->isOpen() || $booking->job_card_id) {
            throw new CrmException('Only an upcoming booking can be moved.');
        }
        $slot = $this->checkSlot($booking->branch_id, $date, $from, $booking->id);
        $booking->update(['booking_date' => $date, 'slot_from' => $slot['from'] ?? null, 'slot_to' => $slot['to'] ?? null, 'reminded_at' => null]);
        if ($booking->lead_id) {
            $this->leads->log($booking->lead_id, 'booking', null, "Moved to {$date}" . ($slot ? " {$slot['from']}" : ''), $user);
        }
        $this->sendConfirmation($booking->fresh());

        return $booking->fresh();
    }

    public function cancel(Booking $booking, string $reason, ?User $user): Booking
    {
        if (!$booking->isOpen()) {
            throw new CrmException('This booking is already closed.');
        }
        $booking->update(['status' => Booking::CANCELLED, 'cancel_reason' => $reason]);
        $this->backToLead($booking, "Booking {$booking->number} cancelled: {$reason}", $user);

        return $booking->fresh();
    }

    public function noShow(Booking $booking, ?User $user = null): Booking
    {
        if (!in_array($booking->status, [Booking::BOOKED, Booking::CONFIRMED], true)) {
            throw new CrmException('Only a booking still waiting can be a no-show.');
        }
        $booking->update(['status' => Booking::NO_SHOW]);
        $lead = $this->backToLead($booking, "Did not come for {$booking->number}", $user);
        if ($lead) {
            $this->journeys->enroll('no_show', ['lead' => $lead]);
        }

        return $booking->fresh();
    }

    protected function backToLead(Booking $booking, string $note, ?User $user): ?Lead
    {
        $lead = $booking->lead;
        if (!$lead || !in_array($lead->status, [Lead::BOOKED], true)) {
            return $lead;
        }
        $lead->update(['next_follow_up_at' => now()]);
        $this->leads->setStatus($lead, Lead::INTERESTED, $user);
        $this->leads->log($lead, 'booking', null, $note, $user);

        return $lead->fresh();
    }

    // ------------------------------------------------------- to a job card

    /**
     * The customer and car for the booking, created from the lead if they don't exist yet
     * (car details given at arrival fill in what the lead didn't say).
     *
     * @param array{registration_no?: ?string, make_id?: ?int, model_id?: ?int, category_id?: ?int, fuel_type?: ?string} $car
     * @return array{0: Customer, 1: Vehicle}
     */
    public function ensureCustomer(Booking $booking, array $car = []): array
    {
        return DB::transaction(function () use ($booking, $car) {
            $lead = $booking->lead;
            $customer = $booking->customer ?? $lead?->customer;

            if (!$customer) {
                $customer = Customer::create([
                    'name' => $lead->name,
                    'phone' => substr($lead->phone, -10),
                    'email' => $lead->email,
                    'city' => $lead->city,
                    'customer_type' => 'individual',
                    'source' => self::customerSource($lead->source?->slug),
                    'lead_id' => $lead->id,
                ]);
            }

            $vehicle = $booking->vehicle ?? $lead?->vehicle;
            $carData = array_filter([
                'registration_no' => isset($car['registration_no']) ? strtoupper(preg_replace('/\s+/', '', (string) $car['registration_no'])) : ($lead?->registration_no),
                'make_id' => $car['make_id'] ?? $lead?->make_id,
                'model_id' => $car['model_id'] ?? $lead?->model_id,
                'category_id' => $car['category_id'] ?? $lead?->category_id,
                'fuel_type' => $car['fuel_type'] ?? $lead?->fuel_type,
            ], fn ($v) => $v !== null && $v !== '');

            if (!$vehicle && !empty($carData['registration_no'])) {
                $vehicle = Vehicle::where('customer_id', $customer->id)->where('registration_no', $carData['registration_no'])->first();
            }
            if (!$vehicle) {
                $vehicle = Vehicle::create(['customer_id' => $customer->id, ...$carData]);
            } else {
                $vehicle->fill(array_filter($carData, fn ($v, $k) => empty($vehicle->{$k}), ARRAY_FILTER_USE_BOTH))->save();
            }

            $booking->update(['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id]);
            $lead?->update(['customer_id' => $customer->id, 'vehicle_id' => $vehicle->id]);

            return [$customer, $vehicle];
        });
    }

    /**
     * What the job card form is filled with when the car arrives.
     */
    public function prefill(Booking $booking, array $car = []): array
    {
        if (!$booking->isOpen()) {
            throw new CrmException('This booking is closed.');
        }
        [$customer, $vehicle] = $this->ensureCustomer($booking, $car);
        $booking->update(['status' => Booking::ARRIVED, 'arrived_at' => now()]);

        $complaints = array_values(array_filter([$booking->lead?->requirement, $booking->notes]));

        return [
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_type' => $booking->service_type,
            'package_id' => $booking->package_id,
            'oil_type_id' => $booking->oil_type_id,
            'service_mode' => JobCard::MODE_DRIVE_IN,
            'complaints' => $complaints,
        ];
    }

    /**
     * Pickup / home visit: the job card opens now with its trip (Phase 5 flow).
     */
    public function openJobCard(Booking $booking, User $user): JobCard
    {
        [$customer, $vehicle] = $this->ensureCustomer($booking);

        $jobCard = app(JobCardService::class)->createJobCard([
            'branch_id' => $booking->branch_id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'service_type' => $booking->service_type,
            'package_id' => $booking->package_id,
            'oil_type_id' => $booking->oil_type_id,
            'service_mode' => $booking->service_mode,
            'service_advisor_id' => $user->id,
            'complaints' => array_values(array_filter([$booking->lead?->requirement, $booking->notes])),
            'trip' => [
                'address' => $booking->address,
                'landmark' => $booking->landmark,
                'maps_url' => $booking->maps_url,
                'slot_date' => $booking->booking_date->toDateString(),
                'slot_from' => $booking->slot_from ? substr($booking->slot_from, 0, 5) : null,
                'slot_to' => $booking->slot_to ? substr($booking->slot_to, 0, 5) : null,
            ],
        ]);

        $this->markConverted($booking, $jobCard, $user);

        return $jobCard;
    }

    /**
     * The job card was opened from the booking.
     */
    public function markConverted(Booking $booking, JobCard $jobCard, ?User $user = null): void
    {
        $booking->update(['status' => Booking::CONVERTED, 'job_card_id' => $jobCard->id, 'arrived_at' => $booking->arrived_at ?? ($booking->service_mode === JobCard::MODE_DRIVE_IN ? now() : null)]);
        if ($booking->lead_id) {
            Lead::whereKey($booking->lead_id)->update(['customer_id' => $jobCard->customer_id, 'vehicle_id' => $jobCard->vehicle_id]);
            $this->leads->log($booking->lead_id, 'booking', null, "Job card {$jobCard->job_number} opened", $user);
        }
    }

    // --------------------------------------------------------------- daily

    /**
     * Tomorrow's bookings get their reminder (run in the evening).
     */
    public function sendReminders(): int
    {
        $count = 0;
        Booking::whereIn('status', [Booking::BOOKED, Booking::CONFIRMED])
            ->whereDate('booking_date', today()->addDay())
            ->whereNull('reminded_at')
            ->get()
            ->each(function (Booking $b) use (&$count) {
                $this->send($b, 'crm.booking_reminder', (string) WorkshopSettings::get('crm.messages.booking_reminder'));
                $b->update(['reminded_at' => now()]);
                $count++;
            });

        return $count;
    }

    /**
     * Drive-ins that did not come by their slot + N hours are no-shows.
     */
    public function markNoShows(): int
    {
        $hours = (int) WorkshopSettings::get('crm.no_show_after_hours');
        $count = 0;
        Booking::whereIn('status', [Booking::BOOKED, Booking::CONFIRMED])
            ->where('service_mode', JobCard::MODE_DRIVE_IN)
            ->whereNull('job_card_id')
            ->whereDate('booking_date', '<=', today())
            ->get()
            ->filter(fn (Booking $b) => Carbon::parse($b->booking_date->toDateString() . ' ' . ($b->slot_to ?? '18:00'))->addHours($hours)->isPast())
            ->each(function (Booking $b) use (&$count) {
                $this->noShow($b);
                $count++;
            });

        return $count;
    }

    // --------------------------------------------------------------- messages

    protected function sendConfirmation(Booking $booking): void
    {
        $this->send($booking, 'crm.booking_confirmed', (string) WorkshopSettings::get('crm.messages.booking_confirmed'));
    }

    protected function send(Booking $booking, string $template, string $text): void
    {
        $booking->loadMissing(['lead', 'customer', 'vehicle', 'branch']);
        $params = [
            'number' => $booking->number,
            'date' => $booking->booking_date->format('D, d M'),
            'slot' => $booking->slot_from ? substr($booking->slot_from, 0, 5) . '–' . substr((string) $booking->slot_to, 0, 5) : 'any time',
            'mode_line' => match ($booking->service_mode) {
                JobCard::MODE_PICKUP_DROP => "Our driver will pick up your car from {$booking->address}.",
                JobCard::MODE_HOME => "Our technician will come to {$booking->address}.",
                default => 'Workshop: ' . trim(($booking->branch?->address ?? '') . ' ' . ($booking->branch?->city ?? '')),
            },
        ];
        $options = ['job_card_id' => $booking->job_card_id, 'vehicle_id' => $booking->vehicle_id, 'branch_id' => $booking->branch_id];

        if ($booking->lead) {
            $this->messages->toLead($booking->lead, $template, $text, $params, null, null, $options);
        } elseif ($booking->customer) {
            $this->messages->toCustomer($booking->customer, $booking->vehicle, $template, $text, $params, null, null, [...$options, 'lead_id' => null]);
        }
    }

    /**
     * Who answers bookings at a branch when the lead has no owner.
     */
    protected function bookingDesk(int $branchId): Collection
    {
        return User::active()
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'crm.bookings.manage'))
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->get();
    }

    public static function customerSource(?string $leadSource): string
    {
        return match ($leadSource) {
            'meta', 'google', 'whatsapp_ad' => 'lead',
            'referral' => 'referral',
            'walk_in' => 'walk_in',
            'website' => 'online',
            default => 'other',
        };
    }

    public function present(Booking $b): array
    {
        $b->loadMissing(['lead.vehicle.make', 'lead.vehicle.model', 'lead.make', 'lead.model', 'customer:id,name,phone', 'vehicle:id,registration_no', 'package:id,name', 'branch:id,name', 'jobCard:id,job_number,status']);

        return [
            'id' => $b->id,
            'number' => $b->number,
            'branch_id' => $b->branch_id,
            'branch' => $b->branch?->name,
            'date' => $b->booking_date->toDateString(),
            'slot_from' => $b->slot_from ? substr($b->slot_from, 0, 5) : null,
            'slot_to' => $b->slot_to ? substr($b->slot_to, 0, 5) : null,
            'name' => $b->customer?->name ?? $b->lead?->name,
            'phone' => $b->customer?->phone ?? $b->lead?->phone,
            'lead_id' => $b->lead_id,
            'customer_id' => $b->customer_id,
            'vehicle_id' => $b->vehicle_id,
            'car' => $b->vehicle?->registration_no ?? $b->lead?->carLabel(),
            'service_type' => $b->service_type,
            'package_id' => $b->package_id,
            'oil_type_id' => $b->oil_type_id,
            'package' => $b->package?->name,
            'service_mode' => $b->service_mode,
            'address' => $b->address,
            'notes' => $b->notes,
            'status' => $b->status,
            'status_label' => Booking::STATUSES[$b->status] ?? $b->status,
            'booked_via' => $b->booked_via,
            'job_card_id' => $b->job_card_id,
            'job_number' => $b->jobCard?->job_number,
            'cancel_reason' => $b->cancel_reason,
        ];
    }
}
