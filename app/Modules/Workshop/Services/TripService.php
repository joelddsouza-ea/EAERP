<?php

namespace App\Modules\Workshop\Services;

use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Workshop\Enums\JobCardStatus as S;
use App\Modules\Workshop\Models\CustomerAddress;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\WorkflowStage;
use App\Modules\Workshop\Notifications\StaffAlert;
use Illuminate\Support\Facades\DB;

/**
 * Pickup, drop and home-visit trips: booking them, giving them to a driver (or the
 * home-service technician), and each step the driver takes on the trip screen.
 *
 * A trip step moves the job too (the workflow's trip edges are taken only here):
 *  - pickup: Start → Pickup En Route; Collected (odometer, fuel, photos/videos: the job's
 *    intake) → Vehicle Collected; Reached workshop → Intake Pending, and the job moves on
 *    by itself, so the advisor does not repeat the intake;
 *  - drop: Start needs the gate pass (no payment → no gate pass → no delivery) and lets the
 *    car out; Delivered (4-side photos) → the job is delivered;
 *  - home visit: Start → Home Visit En Route; Arrived → Intake Pending (the technician
 *    records the intake there); Close visit (photos, gate pass) → delivered.
 */
class TripService
{
    public function __construct(
        protected JobCardStatusService $statuses,
        protected JobLifecycleService $lifecycle,
        protected CustomerMessageService $messages,
    ) {}

    // ------------------------------------------------------------------ booking

    /**
     * Trips for a new pickup & drop or home-service job card. The drop is booked
     * without a slot; the advisor sets it when the car is nearly ready.
     *
     * @param array{address?: ?string, landmark?: ?string, maps_url?: ?string, lat?: ?float, lng?: ?float,
     *     slot_date?: ?string, slot_from?: ?string, slot_to?: ?string, assigned_to?: ?int,
     *     charge?: bool, drop_charge?: bool, save_address?: bool} $data
     */
    public function bookForNewJob(JobCard $jobCard, array $data, User $user): void
    {
        $first = $jobCard->service_mode === JobCard::MODE_HOME ? JobTrip::KIND_HOME_VISIT : JobTrip::KIND_PICKUP;

        $this->create($jobCard, $first, [...$data, 'charge' => !empty($data['charge']) ? self::defaultCharge($first) : 0], $user);

        if ($first === JobTrip::KIND_PICKUP) {
            $this->create($jobCard, JobTrip::KIND_DROP, [
                ...array_intersect_key($data, array_flip(['address', 'landmark', 'maps_url', 'lat', 'lng'])),
                'charge' => !empty($data['drop_charge']) ? self::defaultCharge(JobTrip::KIND_DROP) : 0,
            ], $user);
        }

        if (!empty($data['save_address']) && !empty($data['address'])) {
            $this->saveAddress($jobCard->customer_id, $data);
        }

        // The job starts in this stage without a status change: send its booking message (with the policy) here
        $stage = WorkflowStage::for($jobCard->status);
        if ($stage && $stage->notify_customer !== WorkflowStage::NOTIFY_OFF) {
            $this->messages->prepareForStatus($jobCard, $jobCard->status, $user);
        }
    }

    public function create(JobCard $jobCard, string $kind, array $data, User $user): JobTrip
    {
        if ($kind !== JobTrip::KIND_DROP
            && JobTrip::where('job_card_id', $jobCard->id)->where('kind', $kind)->whereIn('status', JobTrip::OPEN)->exists()) {
            throw new TripException("This job already has an open {$kind}.");
        }
        if ($kind === JobTrip::KIND_DROP
            && JobTrip::where('job_card_id', $jobCard->id)->where('kind', $kind)->where('status', '!=', JobTrip::CANCELLED)->exists()) {
            throw new TripException('This job already has a drop.');
        }
        if ($kind === JobTrip::KIND_DROP && $jobCard->isHomeService()) {
            throw new TripException('A home-service job has no drop: the car stays with the customer.');
        }

        $assignee = $data['assigned_to'] ?? null;
        $trip = JobTrip::create([
            ...$this->fields($data),
            'branch_id' => $jobCard->branch_id,
            'job_card_id' => $jobCard->id,
            'kind' => $kind,
            'status' => $assignee ? JobTrip::SCHEDULED : JobTrip::UNASSIGNED,
            'assigned_to' => $assignee,
            'charge' => round((float) ($data['charge'] ?? 0), 2),
            'created_by' => $user->id,
        ]);

        if ($assignee) {
            $this->assigned($trip, $jobCard, $user);
        }
        $this->refreshDraftBill($jobCard);

        return $trip;
    }

    /**
     * Change the slot, address, charge or who does the trip (until it starts; the charge
     * and notes may change later too).
     */
    public function update(JobTrip $trip, array $data, User $user): JobTrip
    {
        $jobCard = $trip->jobCard;

        if (!$trip->isOpen()) {
            throw new TripException('This trip is finished.');
        }

        $started = !in_array($trip->status, JobTrip::NOT_STARTED, true);
        $fields = $started ? array_intersect_key($this->fields($data), array_flip(['notes'])) : $this->fields($data);
        if (array_key_exists('charge', $data)) {
            $fields['charge'] = round((float) $data['charge'], 2);
        }

        $before = $trip->assigned_to;
        if (array_key_exists('assigned_to', $data)) {
            if ($started && (int) $data['assigned_to'] !== (int) $before) {
                throw new TripException('The trip has started: it cannot be given to someone else now.');
            }
            $fields['assigned_to'] = $data['assigned_to'] ?: null;
            if (!$started) {
                $fields['status'] = $fields['assigned_to'] ? JobTrip::SCHEDULED : JobTrip::UNASSIGNED;
            }
        }

        $trip->update($fields);

        if ($trip->assigned_to && $trip->assigned_to !== $before) {
            $this->assigned($trip, $jobCard, $user);
        }
        if (array_key_exists('charge', $fields)) {
            $this->refreshDraftBill($jobCard);
        }
        if (!empty($data['save_address']) && $trip->address) {
            $this->saveAddress($jobCard->customer_id, $trip->only(['address', 'landmark', 'maps_url', 'lat', 'lng']));
        }

        return $trip->fresh();
    }

    public function cancel(JobTrip $trip, string $reason, User $user): JobTrip
    {
        if (!in_array($trip->status, JobTrip::NOT_STARTED, true)) {
            throw new TripException('A trip on its way cannot be cancelled. The driver can turn back from the trip screen.');
        }

        $jobCard = $trip->jobCard;

        // A pickup / home visit not done: the job waits for the car at the workshop instead
        if ($trip->kind !== JobTrip::KIND_DROP && in_array($jobCard->status, [S::PickupScheduled->value, S::HomeVisitScheduled->value], true)) {
            // onStatusChanged() cancels the trip and, for a home visit, makes it a workshop job
            $this->statuses->change($jobCard, S::IntakePending->value, $user, "Trip cancelled: {$reason}");
        } else {
            $this->markCancelled($trip, $reason, $user);
        }
        $this->refreshDraftBill($jobCard);

        return $trip->fresh();
    }

    // ------------------------------------------------------------ driver steps

    public function start(JobTrip $trip, User $user): JobTrip
    {
        $this->assertStatus($trip, [JobTrip::SCHEDULED], 'started');
        $jobCard = $trip->jobCard;

        return DB::transaction(function () use ($trip, $jobCard, $user) {
            $trip->update(['status' => JobTrip::EN_ROUTE, 'started_at' => now()]);

            match ($trip->kind) {
                JobTrip::KIND_PICKUP => $this->move($jobCard, S::PickupScheduled, S::PickupEnRoute, $trip, $user),
                JobTrip::KIND_HOME_VISIT => $this->move($jobCard, S::HomeVisitScheduled, S::HomeVisitEnRoute, $trip, $user),
                JobTrip::KIND_DROP => $this->leaveWithCar($jobCard, $trip, $user),
            };

            return $trip->fresh();
        });
    }

    /**
     * Pickup: the driver has recorded the car and leaves with it.
     *
     * @param array{km_reading: int, fuel_level: string, notes?: ?string, handover_name?: ?string} $data
     */
    public function collect(JobTrip $trip, array $data, User $user): JobTrip
    {
        $this->assertKind($trip, JobTrip::KIND_PICKUP);
        $this->assertStatus($trip, [JobTrip::EN_ROUTE], 'collected');
        $jobCard = $trip->jobCard;
        $notes = trim((string) ($data['notes'] ?? ''));

        return DB::transaction(function () use ($trip, $jobCard, $data, $user, $notes) {
            // The driver's photos/videos and readings are the intake; the advisor still does the
            // trial drive at the workshop and completes the intake (owner, 2026-10-24)
            $jobCard->update([
                'km_reading' => $data['km_reading'],
                'fuel_level' => $data['fuel_level'],
            ]);

            $this->move($jobCard->fresh(), S::PickupEnRoute, S::VehicleCollected, $trip, $user);

            $trip->update([
                'status' => JobTrip::COLLECTED,
                'arrived_at' => $trip->arrived_at ?? now(),
                'handover_name' => $data['handover_name'] ?? null,
                'notes' => $notes !== '' ? $notes : $trip->notes,
            ]);

            return $trip->fresh();
        });
    }

    public function reachWorkshop(JobTrip $trip, User $user): JobTrip
    {
        $this->assertKind($trip, JobTrip::KIND_PICKUP);
        $this->assertStatus($trip, [JobTrip::COLLECTED], 'closed');
        $jobCard = $trip->jobCard;

        DB::transaction(function () use ($trip, $jobCard, $user) {
            $trip->update(['status' => JobTrip::DONE, 'completed_at' => now()]);
            $this->move($jobCard, S::VehicleCollected, S::IntakePending, $trip, $user);
        });

        // Intake photos are done; the advisor's trial drive completes it, then work starts
        $this->lifecycle->sync($jobCard->fresh(), 'pickup');

        return $trip->fresh();
    }

    /**
     * Home visit: at the customer's place. The intake (odometer, fuel, photos) is recorded
     * on the job there; no trial drive.
     */
    public function arrive(JobTrip $trip, User $user): JobTrip
    {
        $this->assertKind($trip, JobTrip::KIND_HOME_VISIT);
        $this->assertStatus($trip, [JobTrip::EN_ROUTE], 'marked arrived');
        $jobCard = $trip->jobCard;

        DB::transaction(function () use ($trip, $jobCard, $user) {
            $trip->update(['status' => JobTrip::ARRIVED, 'arrived_at' => now()]);
            $jobCard->update([
                'intake_trial_status' => 'skipped',
                'intake_trial_notes' => 'Home service: no trial drive.',
                'intake_trial_by' => $user->id,
                'intake_trial_at' => now(),
            ]);
            $this->move($jobCard->fresh(), S::HomeVisitEnRoute, S::IntakePending, $trip, $user);
        });

        $this->lifecycle->sync($jobCard->fresh(), 'home_visit');

        return $trip->fresh();
    }

    /**
     * Drop: handed over at the customer's place, with the 4-side photos.
     */
    public function deliver(JobTrip $trip, array $data, User $user): JobTrip
    {
        $this->assertKind($trip, JobTrip::KIND_DROP);
        $this->assertStatus($trip, [JobTrip::EN_ROUTE], 'delivered');

        return $this->finishAtCustomer($trip, $data, self::minDropPhotos(), 'the car at the drop (front, rear, left, right)');
    }

    /**
     * Home visit done: closing photos, and the bill paid (gate pass) as at the workshop.
     */
    public function closeVisit(JobTrip $trip, array $data, User $user): JobTrip
    {
        $this->assertKind($trip, JobTrip::KIND_HOME_VISIT);
        $this->assertStatus($trip, [JobTrip::ARRIVED], 'closed');
        $jobCard = $trip->jobCard;

        $billing = app(BillingService::class);
        $pass = $billing->gatePass($jobCard);
        if (!$pass) {
            throw new TripException($jobCard->status === S::Invoiced->value || $jobCard->status === S::ReadyForDelivery->value
                ? 'The bill is not fully paid yet: collect the payment (or get the owner/manager to release it) first.'
                : 'The job is not ready to close: finish the job sheets and final QC, and get the bill approved.');
        }

        return DB::transaction(function () use ($trip, $data, $user, $jobCard, $billing, $pass) {
            $trip = $this->finishAtCustomer($trip, $data, self::minHomeClosePhotos(), 'the finished car', sync: false);
            if (!$pass->vehicle_out_at) {
                $billing->vehicleOut($jobCard, $user); // syncs: the job is delivered
            } else {
                $this->lifecycle->sync($jobCard->fresh(), 'home_visit');
            }

            return $trip;
        });
    }

    /**
     * The driver turns back before collecting / arriving (customer not there, car not ready…).
     */
    public function turnBack(JobTrip $trip, string $reason, User $user): JobTrip
    {
        $this->assertStatus($trip, [JobTrip::EN_ROUTE], 'turned back');
        if ($trip->kind === JobTrip::KIND_DROP) {
            throw new TripException('The car has left on a drop: deliver it, or bring it back and ask the advisor.');
        }
        $jobCard = $trip->jobCard;

        DB::transaction(function () use ($trip, $jobCard, $reason, $user) {
            $trip->update(['status' => JobTrip::SCHEDULED, 'started_at' => null, 'notes' => trim(($trip->notes ? $trip->notes . "\n" : '') . "Turned back: {$reason}")]);
            $trip->kind === JobTrip::KIND_PICKUP
                ? $this->move($jobCard, S::PickupEnRoute, S::PickupScheduled, $trip, $user, "Driver turned back: {$reason}")
                : $this->move($jobCard, S::HomeVisitEnRoute, S::HomeVisitScheduled, $trip, $user, "Technician turned back: {$reason}");
        });

        return $trip->fresh();
    }

    /**
     * Payment taken at the customer's place on a home visit (cash / UPI / card).
     */
    public function collectPayment(JobTrip $trip, array $data, User $user): void
    {
        $this->assertKind($trip, JobTrip::KIND_HOME_VISIT);
        $this->assertStatus($trip, [JobTrip::ARRIVED], 'paid on');

        app(BillingService::class)->recordPayment($trip->jobCard, [
            'parts' => [[
                'method' => $data['method'],
                'amount' => $data['amount'],
                'reference_number' => $data['reference_number'] ?? null,
            ]],
            'notes' => trim('Collected on the home visit. ' . ($data['notes'] ?? '')),
        ], $user);
    }

    // ------------------------------------------------------------ status hooks

    /**
     * Called by JobCardStatusService when a job changes status (inside its transaction).
     */
    public function onStatusChanged(JobCard $jobCard, string $from, string $to, ?User $user, ?string $notes): void
    {
        $open = fn (array $kinds) => JobTrip::where('job_card_id', $jobCard->id)->whereIn('kind', $kinds)->whereIn('status', JobTrip::OPEN)->get();

        if ($to === S::Cancelled->value) {
            $open(JobTrip::KINDS)->each(fn (JobTrip $t) => $this->markCancelled($t, 'Job cancelled', $user));

            return;
        }

        // The car came in to the workshop after all
        if ($to === S::IntakePending->value && in_array($from, [S::PickupScheduled->value, S::HomeVisitScheduled->value], true)) {
            $open([JobTrip::KIND_PICKUP, JobTrip::KIND_HOME_VISIT])
                ->each(fn (JobTrip $t) => $this->markCancelled($t, $notes ?: 'The car came to the workshop.', $user));

            if ($jobCard->isHomeService()) {
                $jobCard->update(['service_mode' => JobCard::MODE_DRIVE_IN]);
                app(JobSheetService::class)->restoreWorkshopOnlyItems($jobCard);
            }
        }

        // Collected at the workshop instead: a drop not yet on its way is not needed
        if ($to === S::Delivered->value) {
            JobTrip::where('job_card_id', $jobCard->id)->where('kind', JobTrip::KIND_DROP)
                ->whereIn('status', JobTrip::NOT_STARTED)->get()
                ->each(fn (JobTrip $t) => $this->markCancelled($t, 'The customer collected the car at the workshop.', $user));
        }
    }

    /**
     * :driver, :driver_phone, :slot and :policy for stage messages.
     */
    public function messageParams(JobCard $jobCard): array
    {
        // The trip the job is at: pickup / home visit until the car is in, the drop afterwards
        $kind = match ($jobCard->status) {
            S::PickupScheduled->value, S::PickupEnRoute->value, S::VehicleCollected->value => JobTrip::KIND_PICKUP,
            S::HomeVisitScheduled->value, S::HomeVisitEnRoute->value => JobTrip::KIND_HOME_VISIT,
            default => $jobCard->isHomeService() ? JobTrip::KIND_HOME_VISIT : JobTrip::KIND_DROP,
        };
        $trip = JobTrip::with('assignee:id,name,phone')
            ->where('job_card_id', $jobCard->id)
            ->where('kind', $kind)
            ->where('status', '!=', JobTrip::CANCELLED)
            ->latest('id')
            ->first();

        return [
            'driver' => $trip?->assignee?->name ?? 'our team',
            'driver_phone' => $trip?->assignee?->phone ?? '',
            'slot' => $trip ? self::slotText($trip) : '',
            'policy' => (string) WorkshopSettings::get('trips.policy_text'),
        ];
    }

    // ------------------------------------------------------------------ access

    /**
     * Whether the user may take the driver's steps on this trip.
     */
    public static function canDrive(JobTrip $trip, User $user): bool
    {
        return $trip->assigned_to === $user->id
            ? $user->hasPermission('workshop.trips.drive')
            : $user->hasPermission('workshop.trips.manage');
    }

    /**
     * The technician on this job's home visit (fills the final QC and closes the visit there).
     */
    public static function isHomeVisitor(JobCard $jobCard, User $user): bool
    {
        return $jobCard->isHomeService()
            && JobTrip::where('job_card_id', $jobCard->id)
                ->where('kind', JobTrip::KIND_HOME_VISIT)
                ->where('assigned_to', $user->id)
                ->where('status', '!=', JobTrip::CANCELLED)
                ->exists();
    }

    // ---------------------------------------------------------------- settings

    public static function defaultCharge(string $kind): float
    {
        return (float) WorkshopSettings::get(match ($kind) {
            JobTrip::KIND_PICKUP => 'trips.pickup_charge',
            JobTrip::KIND_DROP => 'trips.drop_charge',
            default => 'trips.home_visit_charge',
        });
    }

    public static function minDropPhotos(): int
    {
        return (int) WorkshopSettings::get('trips.min_drop_photos');
    }

    public static function minHomeClosePhotos(): int
    {
        return (int) WorkshopSettings::get('trips.min_home_close_photos');
    }

    public static function slotText(JobTrip $trip): string
    {
        if (!$trip->slot_date) {
            return '';
        }
        $time = $trip->slot_from
            ? ' between ' . substr((string) $trip->slot_from, 0, 5) . ($trip->slot_to ? ' and ' . substr((string) $trip->slot_to, 0, 5) : '')
            : '';

        return 'Slot: ' . $trip->slot_date->format('D, d M') . $time . '.';
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Take the job's trip edge (only TripService may), with the trip on the history row.
     */
    protected function move(JobCard $jobCard, S $from, S $to, JobTrip $trip, User $user, ?string $notes = null): void
    {
        $jobCard->refresh();
        if ($jobCard->status !== $from->value) {
            throw new TripException("The job is {$this->statusLabel($jobCard->status)}, not {$from->label()}: ask the advisor.");
        }

        $this->statuses->change($jobCard, $to->value, $user, $notes, false, ['trip_id' => $trip->id, 'via' => 'trip']);
    }

    /**
     * Drop start: the car leaves the workshop on its gate pass.
     */
    protected function leaveWithCar(JobCard $jobCard, JobTrip $trip, User $user): void
    {
        $billing = app(BillingService::class);
        $pass = $billing->gatePass($jobCard);

        if (!$pass) {
            throw new TripException('No gate pass yet: the car leaves only after the bill is paid (or the owner/manager releases it).');
        }
        if (!$pass->vehicle_out_at) {
            $billing->vehicleOut($jobCard, $user);
        }

        $this->messages->prepare($jobCard, 'drop_en_route', $this->messageParams($jobCard), $user);
    }

    protected function finishAtCustomer(JobTrip $trip, array $data, int $minPhotos, string $what, bool $sync = true): JobTrip
    {
        $photos = $trip->media()->where('media_type', JobCardMedia::TYPE_PHOTO)->count();
        if ($photos < $minPhotos) {
            $need = $minPhotos - $photos;
            throw new TripException("Take {$need} more photo" . ($need > 1 ? 's' : '') . " of {$what} first.", ['photos_needed' => $need]);
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $trip->update([
            'status' => JobTrip::DONE,
            'completed_at' => now(),
            'handover_name' => $data['handover_name'] ?? $trip->handover_name,
            'notes' => $notes !== '' ? trim(($trip->notes ? $trip->notes . "\n" : '') . $notes) : $trip->notes,
        ]);

        if ($sync) {
            $this->lifecycle->sync($trip->jobCard->fresh(), 'drop');
        }

        return $trip->fresh();
    }

    protected function markCancelled(JobTrip $trip, string $reason, ?User $user): void
    {
        $trip->update([
            'status' => JobTrip::CANCELLED,
            'cancelled_by' => $user?->id,
            'cancel_reason' => mb_substr($reason, 0, 500),
        ]);
    }

    protected function assigned(JobTrip $trip, JobCard $jobCard, User $by): void
    {
        $jobCard->loadMissing('vehicle:id,registration_no');

        // The home-service technician leads the job (fills its sheets)
        if ($trip->kind === JobTrip::KIND_HOME_VISIT) {
            app(JobCardService::class)->addTechnician($jobCard, $trip->assigned_to, true);
        }

        if ($trip->assigned_to !== $by->id) {
            User::find($trip->assigned_to)?->notify(StaffAlert::tripAssigned($trip, $jobCard, $by->name));
        }
    }

    /**
     * A charge changed: a draft bill picks it up (approved bills are left as they are).
     */
    protected function refreshDraftBill(JobCard $jobCard): void
    {
        $billing = app(BillingService::class);
        $bill = $billing->currentBill($jobCard);

        if ($bill && $bill->kind === Invoice::KIND_PROFORMA && $bill->status === Invoice::DRAFT) {
            try {
                $billing->refreshProforma($bill);
            } catch (BillingException) {
                // The bill shows its own blocker; back office refreshes it later
            }
        }
    }

    protected function saveAddress(int $customerId, array $data): void
    {
        $address = trim((string) $data['address']);
        $existing = CustomerAddress::where('customer_id', $customerId)->where('address', $address)->first();

        $attributes = [
            'landmark' => $data['landmark'] ?? null,
            'maps_url' => $data['maps_url'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ];

        if ($existing) {
            $existing->update(array_filter($attributes, fn ($v) => $v !== null));

            return;
        }

        CustomerAddress::create([
            'customer_id' => $customerId,
            'address' => $address,
            'is_default' => !CustomerAddress::where('customer_id', $customerId)->exists(),
            ...$attributes,
        ]);
    }

    protected function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'address', 'landmark', 'maps_url', 'lat', 'lng', 'slot_date', 'slot_from', 'slot_to', 'notes',
        ]));
    }

    protected function assertKind(JobTrip $trip, string $kind): void
    {
        if ($trip->kind !== $kind) {
            throw new TripException('That step is not part of a ' . str_replace('_', ' ', $trip->kind) . '.');
        }
    }

    protected function assertStatus(JobTrip $trip, array $statuses, string $action): void
    {
        if (!in_array($trip->status, $statuses, true)) {
            throw new TripException(match ($trip->status) {
                JobTrip::UNASSIGNED => 'Give the trip to a driver first.',
                JobTrip::CANCELLED => 'This trip was cancelled.',
                JobTrip::DONE => 'This trip is already done.',
                default => "The trip cannot be {$action} now.",
            });
        }
    }

    private function statusLabel(string $status): string
    {
        return S::tryFrom($status)?->label() ?? $status;
    }
}
