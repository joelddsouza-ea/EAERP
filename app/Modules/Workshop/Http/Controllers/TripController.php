<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Models\User;
use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Services\BillingException;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Http\Controllers\Concerns\ValidatesMediaUpload;
use App\Modules\Workshop\Http\Resources\JobCardMediaResource;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardMedia;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Services\JobLifecycleService;
use App\Modules\Workshop\Services\JobSheetService;
use App\Modules\Workshop\Services\TripException;
use App\Modules\Workshop\Services\TripService;
use App\Modules\Workshop\Workflow\Guards\IntakeCaptured;
use App\Modules\Workshop\Workflow\TransitionBlockedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pickup, drop and home-visit trips: the driver's "My Trips" screen and trip steps,
 * the advisors' board, and booking / changing trips on a job card.
 */
class TripController extends ApiController
{
    use ValidatesMediaUpload;

    /** Statuses in which the pickup / home-visit intake can still change (as IntakeController) */
    private const INTAKE_EDITABLE = [
        'pickup_scheduled', 'pickup_en_route', 'vehicle_collected',
        'home_visit_scheduled', 'home_visit_en_route', 'intake_pending', 'intake_in_progress',
    ];

    public function __construct(
        protected TripService $trips,
        protected JobSheetService $sheets,
    ) {}

    // ------------------------------------------------------------------ lists

    /**
     * The signed-in driver's / technician's trips: open ones and those done today.
     */
    public function mine(Request $request): JsonResponse
    {
        $trips = JobTrip::with($this->relations())
            ->where('assigned_to', $request->user()->id)
            ->where(fn ($q) => $q->whereIn('status', JobTrip::OPEN)
                ->orWhere(fn ($q) => $q->where('status', JobTrip::DONE)->where('completed_at', '>=', today())))
            ->orderByRaw('slot_date IS NULL, slot_date')
            ->orderBy('slot_from')
            ->get();

        return $this->success($trips->map(fn (JobTrip $t) => $this->summary($t))->values());
    }

    /**
     * Board for advisors / managers: trips by day (default today), plus every unassigned one.
     */
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'nullable|date',
            'assigned_to' => 'nullable|integer',
        ]);
        $date = $data['date'] ?? today()->toDateString();

        $trips = JobTrip::with($this->relations())
            ->whereIn('branch_id', $this->branchIds())
            ->where('status', '!=', JobTrip::CANCELLED)
            ->when($data['assigned_to'] ?? null, fn ($q, $id) => $q->where('assigned_to', $id))
            ->where(fn ($q) => $q->whereDate('slot_date', $date)
                // Not yet booked for a day, or still going from an earlier day
                ->orWhere(fn ($q) => $q->whereIn('status', JobTrip::OPEN)->where(fn ($q) => $q->whereNull('slot_date')->orWhereDate('slot_date', '<', $date)))
                ->orWhere(fn ($q) => $q->where('status', JobTrip::DONE)->whereDate('completed_at', $date)))
            ->orderByRaw('slot_date IS NULL, slot_date')
            ->orderBy('slot_from')
            ->get();

        return $this->success([
            'date' => $date,
            'trips' => $trips->map(fn (JobTrip $t) => $this->summary($t))->values(),
            'people' => $this->people(),
        ]);
    }

    public function forJob(JobCard $jobCard): JsonResponse
    {
        $trips = JobTrip::with($this->relations())->where('job_card_id', $jobCard->id)->orderBy('id')->get();

        return $this->success([
            'service_mode' => $jobCard->service_mode,
            'trips' => $trips->map(fn (JobTrip $t) => $this->summary($t))->values(),
            'people' => $this->people(),
            'charges' => [
                'pickup' => TripService::defaultCharge(JobTrip::KIND_PICKUP),
                'drop' => TripService::defaultCharge(JobTrip::KIND_DROP),
                'home_visit' => TripService::defaultCharge(JobTrip::KIND_HOME_VISIT),
            ],
            'addresses' => $jobCard->customer?->addresses()->get(['id', 'label', 'address', 'landmark', 'maps_url', 'lat', 'lng']) ?? [],
        ]);
    }

    public function show(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeView($trip, $request->user());

        return $this->success($this->detail($trip, $request->user()));
    }

    public function addresses(Customer $customer): JsonResponse
    {
        return $this->success($customer->addresses()->get());
    }

    // --------------------------------------------------------------- booking

    public function store(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(JobTrip::KINDS)],
            ...$this->tripRules(),
        ]);

        if ($jobCard->isClosed()) {
            throw new TripException('This job is closed.');
        }

        $trip = $this->trips->create($jobCard, $data['kind'], $this->withCharge($data, $data['kind']), $request->user());

        return $this->created($this->detail($trip, $request->user()), $trip->label() . ' booked');
    }

    public function update(Request $request, JobTrip $trip): JsonResponse
    {
        $data = $request->validate([...$this->tripRules(), 'save_address' => 'nullable|boolean']);

        $trip = $this->trips->update($trip, $this->withCharge($data, $trip->kind, $trip), $request->user());

        return $this->success($this->detail($trip, $request->user()), 'Trip saved');
    }

    public function cancel(Request $request, JobTrip $trip): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        return $this->guarded(fn () => $this->trips->cancel($trip, $data['reason'], $request->user()), $request, 'Trip cancelled');
    }

    // ------------------------------------------------------------ driver steps

    public function start(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());

        return $this->guarded(fn () => $this->trips->start($trip, $request->user()), $request, 'On the way');
    }

    public function collect(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $data = $request->validate([
            'km_reading' => 'required|integer|min:0|max:9999999',
            'fuel_level' => 'required|in:empty,quarter,half,three_quarter,full',
            'notes' => 'nullable|string|max:2000',
            'handover_name' => 'nullable|string|max:120',
        ]);

        return $this->guarded(fn () => $this->trips->collect($trip, $data, $request->user()), $request, 'Car collected');
    }

    public function reachWorkshop(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());

        return $this->guarded(fn () => $this->trips->reachWorkshop($trip, $request->user()), $request, 'Car handed over at the workshop');
    }

    public function arrive(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());

        return $this->guarded(fn () => $this->trips->arrive($trip, $request->user()), $request, 'Arrived');
    }

    public function deliver(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $data = $request->validate(['notes' => 'nullable|string|max:2000', 'handover_name' => 'nullable|string|max:120']);

        return $this->guarded(fn () => $this->trips->deliver($trip, $data, $request->user()), $request, 'Car delivered');
    }

    public function close(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $data = $request->validate(['notes' => 'nullable|string|max:2000', 'handover_name' => 'nullable|string|max:120']);

        return $this->guarded(fn () => $this->trips->closeVisit($trip, $data, $request->user()), $request, 'Home visit closed');
    }

    public function turnBack(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $data = $request->validate(['reason' => 'required|string|max:500']);

        return $this->guarded(fn () => $this->trips->turnBack($trip, $data['reason'], $request->user()), $request, 'Trip back to scheduled');
    }

    /**
     * Home visit: odometer and fuel at the customer's place (the job's intake).
     */
    public function readings(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $data = $request->validate([
            'km_reading' => 'required|integer|min:0|max:9999999',
            'fuel_level' => 'required|in:empty,quarter,half,three_quarter,full',
        ]);
        $jobCard = $trip->jobCard;

        if ($trip->kind !== JobTrip::KIND_HOME_VISIT || !in_array($jobCard->status, self::INTAKE_EDITABLE, true)) {
            throw new TripException('The readings can no longer change: work has started.');
        }

        $jobCard->update($data);
        app(JobLifecycleService::class)->sync($jobCard->fresh(), 'home_visit');

        return $this->success($this->detail($trip->fresh(), $request->user()), 'Readings saved');
    }

    public function payment(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $data = $request->validate([
            'method' => ['required', Rule::in(Payment::METHODS)],
            'amount' => 'required|numeric|min:1|max:10000000',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:300',
        ]);

        return $this->guarded(function () use ($trip, $data, $request) {
            $this->trips->collectPayment($trip, $data, $request->user());

            return $trip->fresh();
        }, $request, 'Payment recorded');
    }

    // ------------------------------------------------------------------ media

    /**
     * purpose "intake": pickup / home-visit inventory photos and videos (the job's intake,
     * no maximum). purpose "handover": drop photos (by side) or home-visit closing photos.
     */
    public function uploadMedia(Request $request, JobTrip $trip): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $this->validateMediaUpload($request);
        $data = $request->validate([
            'purpose' => 'required|in:intake,handover',
            'side' => ['nullable', Rule::in(JobTrip::SIDES)],
        ]);
        $jobCard = $trip->jobCard;

        if ($data['purpose'] === 'intake') {
            $this->assertIntakeMedia($trip, $jobCard);
            $media = $this->sheets->storeMedia(
                $jobCard, $request->file('file'), 'job_card', $jobCard->id, 'intake',
                ($trip->kind === JobTrip::KIND_PICKUP ? 'Pickup' : 'Home visit') . " — {$jobCard->job_number}",
                $request->user(), $request->file('poster'), $request->only(['duration_seconds', 'description', 'captured_at']),
            );
            // The first photo starts the intake (home visit at the customer's place)
            app(JobLifecycleService::class)->sync($jobCard->fresh(), 'intake_media');
        } else {
            $this->assertHandoverMedia($trip);
            $media = $this->sheets->storeMedia(
                $jobCard, $request->file('file'), 'job_trip', $trip->id, 'trips',
                ($data['side'] ?? null) ? ucfirst($data['side']) . ' — ' . ($trip->kind === JobTrip::KIND_DROP ? 'at drop' : 'home visit done') : "{$trip->label()} — {$jobCard->job_number}",
                $request->user(), $request->file('poster'), $request->only(['duration_seconds', 'description', 'captured_at']),
            );
            if ($data['side'] ?? null) {
                $media->update(['tag' => $data['side']]);
            }
        }

        return $this->created([
            'media' => new JobCardMediaResource($media),
            'trip' => $this->detail($trip->fresh(), $request->user()),
        ], 'Uploaded');
    }

    public function deleteMedia(Request $request, JobTrip $trip, JobCardMedia $media): JsonResponse
    {
        $this->authorizeDrive($trip, $request->user());
        $jobCard = $trip->jobCard;

        $isIntake = $media->attachable_type === 'job_card' && $media->attachable_id === $jobCard->id;
        $isHandover = $media->attachable_type === 'job_trip' && $media->attachable_id === $trip->id;
        if (!$isIntake && !$isHandover) {
            return $this->notFound('Media not found on this trip.');
        }
        $isIntake ? $this->assertIntakeMedia($trip, $jobCard) : $this->assertHandoverMedia($trip);

        $user = $request->user();
        if ($media->uploaded_by !== $user->id && !$user->hasPermission('workshop.job-sheets.delete-any-media')) {
            return $this->forbidden('You can only delete photos/videos you took.');
        }
        $media->delete();

        return $this->success($this->detail($trip->fresh(), $user), 'Deleted');
    }

    // ---------------------------------------------------------------- settings

    public function settings(): JsonResponse
    {
        return $this->success($this->settingsData());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pickup_charge' => 'sometimes|required|numeric|min:0|max:100000',
            'drop_charge' => 'sometimes|required|numeric|min:0|max:100000',
            'home_visit_charge' => 'sometimes|required|numeric|min:0|max:100000',
            'policy_text' => 'sometimes|nullable|string|max:2000',
            'min_drop_photos' => 'sometimes|required|integer|min:0|max:20',
            'min_home_close_photos' => 'sometimes|required|integer|min:0|max:20',
        ]);

        foreach ($data as $key => $value) {
            WorkshopSettings::set("trips.{$key}", is_numeric($value) ? $value + 0 : (string) $value);
        }

        return $this->success($this->settingsData(), 'Settings saved');
    }

    // ---------------------------------------------------------------- helpers

    private function settingsData(): array
    {
        return [
            'pickup_charge' => (float) WorkshopSettings::get('trips.pickup_charge'),
            'drop_charge' => (float) WorkshopSettings::get('trips.drop_charge'),
            'home_visit_charge' => (float) WorkshopSettings::get('trips.home_visit_charge'),
            'policy_text' => (string) WorkshopSettings::get('trips.policy_text'),
            'min_drop_photos' => TripService::minDropPhotos(),
            'min_home_close_photos' => TripService::minHomeClosePhotos(),
        ];
    }

    /**
     * Run a trip step; workflow and billing refusals come back as 422s with their message.
     */
    private function guarded(callable $step, Request $request, string $message): JsonResponse
    {
        try {
            $trip = $step();
        } catch (TransitionBlockedException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors());
        } catch (BillingException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->detail($trip->fresh(), $request->user()), $message);
    }

    private function tripRules(): array
    {
        return [
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'address' => 'sometimes|nullable|string|max:1000',
            'landmark' => 'nullable|string|max:255',
            'maps_url' => 'nullable|url|max:500',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'slot_date' => 'nullable|date',
            'slot_from' => 'nullable|date_format:H:i',
            'slot_to' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
            // Tick: charge the amount set in the masters; or an amount
            'with_charge' => 'nullable|boolean',
            'charge' => 'nullable|numeric|min:0|max:100000',
        ];
    }

    private function withCharge(array $data, string $kind, ?JobTrip $trip = null): array
    {
        if (array_key_exists('with_charge', $data)) {
            $data['charge'] = $data['with_charge']
                ? ($data['charge'] ?? ($trip && (float) $trip->charge > 0 ? (float) $trip->charge : TripService::defaultCharge($kind)))
                : 0;
        }
        unset($data['with_charge'], $data['kind']);

        return $data;
    }

    private function assertIntakeMedia(JobTrip $trip, JobCard $jobCard): void
    {
        $ok = match ($trip->kind) {
            JobTrip::KIND_PICKUP => in_array($trip->status, [JobTrip::EN_ROUTE, JobTrip::COLLECTED], true),
            JobTrip::KIND_HOME_VISIT => $trip->status === JobTrip::ARRIVED,
            default => false,
        };
        if (!$ok || !in_array($jobCard->status, self::INTAKE_EDITABLE, true)) {
            throw new TripException($trip->kind === JobTrip::KIND_DROP
                ? 'Drop photos go under the handover.'
                : 'Intake photos can be taken only on the way to / at the car, before work starts.');
        }
    }

    private function assertHandoverMedia(JobTrip $trip): void
    {
        $ok = match ($trip->kind) {
            JobTrip::KIND_DROP => $trip->status === JobTrip::EN_ROUTE,
            JobTrip::KIND_HOME_VISIT => $trip->status === JobTrip::ARRIVED,
            default => false,
        };
        if (!$ok) {
            throw new TripException($trip->kind === JobTrip::KIND_PICKUP
                ? 'Pickup photos are the intake photos.'
                : 'Handover photos are taken at the customer\'s place, before closing the trip.');
        }
    }

    private function authorizeDrive(JobTrip $trip, User $user): void
    {
        abort_unless(TripService::canDrive($trip, $user), 403, 'This trip is given to someone else.');
    }

    private function authorizeView(JobTrip $trip, User $user): void
    {
        abort_unless(
            $trip->assigned_to === $user->id || $user->hasAnyPermission(['workshop.trips.view', 'workshop.trips.manage']),
            403,
            'This trip is given to someone else.'
        );
    }

    private function branchIds(): array
    {
        $context = app('branch.context');

        return $context->hasAllBranchesAccess() ? \App\Core\Models\Branch::pluck('id')->all() : $context->getAccessibleBranchIds();
    }

    private function relations(): array
    {
        return [
            'assignee:id,name,phone',
            'jobCard:id,job_number,status,service_mode,customer_id,vehicle_id,branch_id',
            'jobCard.customer:id,name,phone,whatsapp_phone',
            'jobCard.vehicle:id,registration_no,make_id,model_id,color',
            'jobCard.vehicle.make:id,name',
            'jobCard.vehicle.model:id,name',
        ];
    }

    /**
     * People a trip can be given to: drivers for pickups / drops, technicians for home visits.
     */
    private function people(): array
    {
        $branchIds = $this->branchIds();

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workshop.trips.drive'))
            ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds))
            ->with('roles:id,slug,name')
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'roles' => $u->roles->pluck('slug')->values(),
                'is_driver' => $u->roles->contains('slug', 'driver'),
            ])->values()->all();
    }

    private function summary(JobTrip $trip): array
    {
        $job = $trip->jobCard;
        $vehicle = $job?->vehicle;

        return [
            'id' => $trip->id,
            'kind' => $trip->kind,
            'label' => $trip->label(),
            'status' => $trip->status,
            'assigned_to' => $trip->assigned_to,
            'assignee' => $trip->assignee ? ['id' => $trip->assignee->id, 'name' => $trip->assignee->name, 'phone' => $trip->assignee->phone] : null,
            'address' => $trip->address,
            'landmark' => $trip->landmark,
            'maps_url' => $trip->maps_url,
            'lat' => $trip->lat,
            'lng' => $trip->lng,
            'navigate_url' => $trip->navigateUrl(),
            'slot_date' => $trip->slot_date?->toDateString(),
            'slot_from' => $trip->slot_from ? substr((string) $trip->slot_from, 0, 5) : null,
            'slot_to' => $trip->slot_to ? substr((string) $trip->slot_to, 0, 5) : null,
            'charge' => (float) $trip->charge,
            'started_at' => $trip->started_at?->toIso8601String(),
            'arrived_at' => $trip->arrived_at?->toIso8601String(),
            'completed_at' => $trip->completed_at?->toIso8601String(),
            'handover_name' => $trip->handover_name,
            'notes' => $trip->notes,
            'cancel_reason' => $trip->cancel_reason,
            'job' => $job ? [
                'id' => $job->id,
                'job_number' => $job->job_number,
                'status' => $job->status,
                'status_label' => JobCardStatus::tryFrom($job->status)?->label() ?? $job->status,
                'service_mode' => $job->service_mode,
                'customer' => $job->customer ? [
                    'name' => $job->customer->name,
                    'phone' => $job->customer->phone,
                    'whatsapp_phone' => $job->customer->whatsapp_phone,
                ] : null,
                'vehicle' => $vehicle ? [
                    'registration_no' => $vehicle->registration_no,
                    'name' => trim(($vehicle->make?->name ?? '') . ' ' . ($vehicle->model?->name ?? '')),
                    'color' => $vehicle->color,
                ] : null,
            ] : null,
        ];
    }

    /**
     * Everything the trip screen needs for the next step.
     */
    private function detail(JobTrip $trip, User $user): array
    {
        $trip->loadMissing($this->relations());
        $job = JobCard::withoutGlobalScopes()->findOrFail($trip->job_card_id);
        $billing = app(BillingService::class);

        $intake = null;
        if ($trip->kind !== JobTrip::KIND_DROP) {
            $counts = IntakeCaptured::mediaCounts($job);
            $intake = [
                'km_reading' => $job->km_reading,
                'fuel_level' => $job->fuel_level,
                'counts' => $counts,
                'min_photos' => IntakeCaptured::minPhotos(),
                'min_videos' => IntakeCaptured::minVideos(),
                'editable' => in_array($job->status, self::INTAKE_EDITABLE, true),
                'media' => JobCardMediaResource::collection($job->intakeMedia()->orderBy('id')->get())->resolve(),
            ];
        }

        $handover = null;
        if ($trip->kind !== JobTrip::KIND_PICKUP) {
            $media = $trip->media()->get();
            $handover = [
                'min_photos' => $trip->kind === JobTrip::KIND_DROP ? TripService::minDropPhotos() : TripService::minHomeClosePhotos(),
                'photos' => $media->where('media_type', JobCardMedia::TYPE_PHOTO)->count(),
                'sides' => JobTrip::SIDES,
                'media' => JobCardMediaResource::collection($media)->resolve(),
            ];
        }

        $gatePass = $billing->gatePass($job);
        $bill = null;
        if ($trip->kind === JobTrip::KIND_HOME_VISIT) {
            $summary = $billing->summary($job);
            $bill = [
                'total' => $summary['bill_total'],
                'paid' => $summary['paid'],
                'balance' => $summary['balance'],
                'methods' => Payment::METHODS,
            ];
        }

        return [
            ...$this->summary($trip),
            'can_drive' => TripService::canDrive($trip, $user),
            'can_manage' => $user->hasPermission('workshop.trips.manage'),
            'intake' => $intake,
            'handover' => $handover,
            'gate_pass' => $gatePass ? ['number' => $gatePass->number, 'vehicle_out_at' => $gatePass->vehicle_out_at?->toIso8601String()] : null,
            'bill' => $bill,
            // Home visit: work goes on at the customer's place on the job sheet
            'job_sheet_url' => $trip->kind === JobTrip::KIND_HOME_VISIT ? "/job-cards/{$job->id}/sheet" : null,
        ];
    }
}
