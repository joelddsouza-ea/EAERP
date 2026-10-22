<?php

namespace App\Modules\Fleet\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\CustomerApp\Support\AppViewer;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Models\FleetReport;
use App\Modules\Fleet\Services\FleetDashboardService;
use App\Modules\Fleet\Services\FleetDriverService;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetReportService;
use App\Modules\Fleet\Services\OdometerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fleet screens in the customer app. The owner sees the dashboard and manages drivers; owner
 * and drivers report problems and log km (a driver only for the cabs linked to them).
 */
class FleetAppController extends ApiController
{
    protected function viewer(Request $request): AppViewer
    {
        return $request->attributes->get('viewer');
    }

    protected function owner(Request $request): AppViewer
    {
        $viewer = $this->viewer($request);
        abort_unless($viewer->isFleetOwner(), 403, 'Only the fleet owner can see this.');

        return $viewer;
    }

    protected function fleetMember(Request $request): AppViewer
    {
        $viewer = $this->viewer($request);
        abort_unless($viewer->isDriver() || $viewer->isFleetOwner(), 404);

        return $viewer;
    }

    protected function guarded(callable $fn, string $message = 'Success'): JsonResponse
    {
        try {
            return $this->success($fn(), $message);
        } catch (FleetException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors);
        }
    }

    // ------------------------------------------------------------------ owner

    public function dashboard(Request $request, FleetDashboardService $dashboard): JsonResponse
    {
        return $this->success($dashboard->build($this->owner($request)->customer));
    }

    public function drivers(Request $request, FleetDriverService $drivers): JsonResponse
    {
        $fleet = $this->owner($request)->customer;

        return $this->success([
            'drivers' => $drivers->list($fleet),
            'cabs' => $fleet->vehicles()->where('is_active', true)->orderBy('registration_no')->get(['id', 'registration_no'])
                ->map(fn ($v) => ['id' => $v->id, 'registration_no' => $v->registration_no])->values(),
        ]);
    }

    public function addDriver(Request $request, FleetDriverService $drivers): JsonResponse
    {
        $fleet = $this->owner($request)->customer;
        $data = $this->driverInput($request);

        return $this->guarded(fn () => $drivers->present($drivers->save($fleet, $data, null, FleetDriver::VIA_OWNER_APP)), 'Driver added. They can sign in to the app with their mobile number.');
    }

    public function updateDriver(Request $request, int $driver, FleetDriverService $drivers): JsonResponse
    {
        $fleet = $this->owner($request)->customer;
        $row = FleetDriver::where('customer_id', $fleet->id)->findOrFail($driver);
        $data = $this->driverInput($request, true);

        return $this->guarded(fn () => $drivers->present($drivers->save($fleet, $data + ['name' => $row->name, 'phone' => $row->phone], $row, FleetDriver::VIA_OWNER_APP)), 'Saved');
    }

    public function signOutDriver(Request $request, int $driver, FleetDriverService $drivers): JsonResponse
    {
        $fleet = $this->owner($request)->customer;
        $row = FleetDriver::where('customer_id', $fleet->id)->findOrFail($driver);

        return $this->success(['signed_out' => $drivers->signOut($row)], "{$row->name} is signed out on every phone");
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

    // ------------------------------------------------------------------ owner and drivers

    public function reports(Request $request, FleetReportService $reports): JsonResponse
    {
        $viewer = $this->fleetMember($request);
        $rows = $viewer->scope(FleetReport::where('customer_id', $viewer->customer->id))->latest('id')->limit(50)->get();

        return $this->success([
            'reports' => $rows->map(fn (FleetReport $r) => $reports->present($r))->values(),
            'kinds' => collect(FleetReport::KINDS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function report(Request $request, FleetReportService $reports): JsonResponse
    {
        $viewer = $this->fleetMember($request);
        $data = $request->validate([
            'vehicle_id' => 'required|integer',
            'kind' => 'required|in:' . implode(',', array_keys(FleetReport::KINDS)),
            'description' => 'required|string|min:3|max:2000',
            'odometer_km' => 'nullable|integer|min:0|max:3000000',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'location_text' => 'nullable|string|max:255',
            'media' => 'nullable|array|max:' . FleetReportService::MAX_MEDIA,
            'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,image/heic,video/mp4,video/quicktime,video/webm,video/3gpp|max:' . (int) config('workshop.job_sheets.max_video_kb', 51200),
        ], ['description.required' => 'Tell us what is wrong.']);

        return $this->guarded(fn () => $reports->present($reports->create($viewer, $data, $request->file('media') ?? [])),
            ($data['kind'] === 'breakdown' ? 'Help is on the way: ' : '') . 'The workshop has your report and will call you shortly.');
    }

    public function odometer(Request $request, int $vehicle, OdometerService $odometer): JsonResponse
    {
        $viewer = $this->fleetMember($request);

        return $this->success($odometer->history($viewer->vehicle($vehicle)));
    }

    public function logOdometer(Request $request, int $vehicle, OdometerService $odometer): JsonResponse
    {
        $viewer = $this->fleetMember($request);
        $car = $viewer->vehicle($vehicle);
        $data = $request->validate([
            'km' => 'required|integer|min:0|max:3000000',
            'photo' => 'nullable|image|max:' . (int) config('workshop.job_sheets.max_photo_kb'),
        ]);

        return $this->guarded(fn () => $odometer->present($odometer->log($car, (int) $data['km'], $viewer->isDriver() ? 'driver' : 'owner', $viewer->driver, $request->file('photo'))), 'Km saved');
    }
}
