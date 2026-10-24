<?php

namespace App\Modules\Fleet\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Support\WorkshopSettings;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetReport;
use App\Modules\Fleet\Models\FleetReportMedia;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Services\FleetApprovalService;
use App\Modules\Fleet\Services\FleetDriverService;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetReportService;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Staff side of fleets: the Fleet tab on the customer page (fleet on/off, the driver limit,
 * drivers and their cabs, problem reports), the Fleet reports queue, and km readings.
 */
class FleetStaffController extends ApiController
{
    public function __construct(
        protected FleetDriverService $drivers,
        protected FleetReportService $reports,
    ) {}

    protected function guarded(callable $fn, string $message = 'Success'): JsonResponse
    {
        try {
            return $this->success($fn(), $message);
        } catch (FleetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }
    }

    // ------------------------------------------------------------------ Masters › Fleet Cabs

    public function settings(): JsonResponse
    {
        return $this->success($this->settingsData());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'driver_limit' => 'sometimes|required|numeric|min:0|max:1000000',
            'service_due_km_warning' => 'sometimes|required|integer|min:0|max:10000',
        ]);
        foreach ($data as $key => $value) {
            WorkshopSettings::set("fleet.{$key}", $value + 0);
        }

        return $this->success($this->settingsData(), 'Settings saved');
    }

    private function settingsData(): array
    {
        return [
            'driver_limit' => (float) WorkshopSettings::get('fleet.driver_limit'),
            'service_due_km_warning' => (int) WorkshopSettings::get('fleet.service_due_km_warning'),
        ];
    }

    // ------------------------------------------------------------------ customer page › Fleet

    public function show(Customer $customer): JsonResponse
    {
        return $this->success($this->state($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'is_fleet' => 'sometimes|boolean',
            'fleet_driver_limit' => 'nullable|numeric|min:0|max:1000000',
            // Cabs the owner drives himself
            'owner_cab_ids' => 'sometimes|array|max:200',
            'owner_cab_ids.*' => 'integer',
        ]);
        if (isset($data['owner_cab_ids'])) {
            $data['owner_cab_ids'] = Vehicle::where('customer_id', $customer->id)->whereIn('id', $data['owner_cab_ids'])->pluck('id')->values()->all();
        }
        // Not a fleet any more: FleetServiceProvider signs its drivers out
        $customer->update($data);

        return $this->success($this->state($customer->fresh()), 'Saved');
    }

    public function addDriver(Request $request, Customer $customer): JsonResponse
    {
        $data = $this->driverInput($request);

        return $this->guarded(fn () => $this->drivers->present($this->drivers->save($customer, $data, null, FleetDriver::VIA_STAFF, $request->user())), 'Driver added');
    }

    public function updateDriver(Request $request, Customer $customer, FleetDriver $driver): JsonResponse
    {
        abort_unless($driver->customer_id === $customer->id, 404);
        $data = $this->driverInput($request, true) + ['name' => $driver->name, 'phone' => $driver->phone];

        return $this->guarded(fn () => $this->drivers->present($this->drivers->save($customer, $data, $driver, FleetDriver::VIA_STAFF, $request->user())), 'Saved');
    }

    public function signOutDriver(Customer $customer, FleetDriver $driver): JsonResponse
    {
        abort_unless($driver->customer_id === $customer->id, 404);

        return $this->success(['signed_out' => $this->drivers->signOut($driver)], "{$driver->name} is signed out on every phone");
    }

    /**
     * The job card form: a fleet's active drivers, the cab's own drivers first.
     */
    public function driversForJob(Request $request, Customer $customer): JsonResponse
    {
        if (!$customer->is_fleet) {
            return $this->success(['drivers' => [], 'owner_drives' => false, 'owner_name' => $customer->name]);
        }
        $vehicleId = $request->integer('vehicle_id') ?: null;
        $rows = FleetDriver::with('vehicles:id')->where('customer_id', $customer->id)->where('is_active', true)->orderBy('name')->get()
            ->map(fn (FleetDriver $d) => ['id' => $d->id, 'name' => $d->name, 'phone' => $d->phone, 'drives_this_cab' => $vehicleId && $d->vehicles->contains('id', $vehicleId)])
            ->sortByDesc('drives_this_cab')->values();

        // The owner drives this cab: the job card records no driver (the owner gets the messages)
        return $this->success(['drivers' => $rows, 'owner_drives' => $customer->ownerDrives($vehicleId), 'owner_name' => $customer->name]);
    }

    protected function driverInput(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "{$required}|string|max:120",
            'phone' => "{$required}|string|max:20",
            'notes' => 'nullable|string|max:255',
            'vehicle_ids' => 'sometimes|array|max:200',
            'vehicle_ids.*' => 'integer',
            'is_active' => 'sometimes|boolean',
        ]);
    }

    protected function state(Customer $customer): array
    {
        $reports = FleetReport::where('customer_id', $customer->id)->latest('id')->limit(20)->get();

        return [
            'is_fleet' => (bool) $customer->is_fleet,
            'fleet_driver_limit' => $customer->fleet_driver_limit !== null ? (float) $customer->fleet_driver_limit : null,
            'default_limit' => (float) WorkshopSettings::get('fleet.driver_limit'),
            'limit' => app(FleetApprovalService::class)->limitFor($customer),
            'owner_name' => $customer->name,
            'owner_cab_ids' => array_map('intval', $customer->owner_cab_ids ?? []),
            'drivers' => $customer->is_fleet ? $this->drivers->list($customer) : [],
            'cabs' => Vehicle::where('customer_id', $customer->id)->where('is_active', true)->orderBy('registration_no')->get(['id', 'registration_no'])
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'registration_no' => $v->registration_no, 'km' => OdometerReading::latestFor($v->id)?->km])->values(),
            'reports' => $reports->map(fn (FleetReport $r) => $this->reports->present($r, true))->values(),
        ];
    }

    // ------------------------------------------------------------------ Fleet reports queue

    public function reports(Request $request): JsonResponse
    {
        $status = $request->input('status', 'active');
        $branchId = app('branch.context')->getCurrentBranchId();
        $rows = FleetReport::query()
            ->when($status === 'active', fn ($q) => $q->whereIn('status', [FleetReport::OPEN, FleetReport::BOOKED]))
            ->when($status !== 'active' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->when($request->integer('customer_id'), fn ($q, $c) => $q->where('customer_id', $c))
            ->orderByRaw("CASE WHEN kind = 'breakdown' AND status = 'open' THEN 0 ELSE 1 END")
            ->latest('id')->limit(100)->get();

        return $this->success([
            'reports' => $rows->map(fn (FleetReport $r) => $this->reports->present($r, true))->values(),
            'counts' => [
                'open' => FleetReport::where('status', FleetReport::OPEN)->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))->count(),
            ],
        ]);
    }

    public function book(Request $request, FleetReport $report): JsonResponse
    {
        $data = $request->validate([
            'booking_date' => 'required|date|after_or_equal:today',
            'slot_from' => 'nullable|date_format:H:i',
            'service_mode' => 'required|in:' . implode(',', JobCard::MODES),
            'branch_id' => 'nullable|integer|exists:branches,id',
            'address' => 'required_unless:service_mode,drive_in|nullable|string|max:1000',
            'landmark' => 'nullable|string|max:255',
            'maps_url' => 'nullable|url|max:500',
            'note' => 'nullable|string|max:500',
        ], ['address.required_unless' => 'Enter where to pick the cab up.']);

        return $this->guarded(fn () => $this->reports->present($this->reports->book($report, $data, $request->user()), true), 'Booked');
    }

    public function close(Request $request, FleetReport $report): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|max:500'], ['note.required' => 'Say why it is closed (e.g. solved on the phone).']);

        return $this->success($this->reports->present($this->reports->close($report, $data['note'], $request->user()), true), 'Closed');
    }

    // ------------------------------------------------------------------ km

    public function odometer(Vehicle $vehicle, OdometerService $odometer): JsonResponse
    {
        return $this->success($odometer->history($vehicle, 50));
    }

    public function logOdometer(Request $request, Vehicle $vehicle, OdometerService $odometer): JsonResponse
    {
        $data = $request->validate(['km' => 'required|integer|min:0|max:3000000']);

        return $this->guarded(fn () => $odometer->present($odometer->log($vehicle, (int) $data['km'], 'staff', null, null, $request->user())), 'Km saved');
    }

    // ------------------------------------------------------------------ files (signed links)

    public function reportMedia(FleetReportMedia $media)
    {
        abort_unless(Storage::disk($media->file_disk)->exists($media->file_path), 404);

        return Storage::disk($media->file_disk)->response($media->file_path, null, ['Cache-Control' => 'private, max-age=600']);
    }

    public function odometerPhoto(OdometerReading $reading)
    {
        abort_unless($reading->photo_path && Storage::disk($reading->photo_disk)->exists($reading->photo_path), 404);

        return Storage::disk($reading->photo_disk)->response($reading->photo_path, null, ['Cache-Control' => 'private, max-age=600']);
    }
}
