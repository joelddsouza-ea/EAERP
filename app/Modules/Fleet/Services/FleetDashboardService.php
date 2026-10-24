<?php

namespace App\Modules\Fleet\Services;

use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\CustomerApp\Services\CustomerPortalService;
use App\Modules\Fleet\Models\FleetReport;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\Vehicle;

/**
 * The fleet owner's dashboard: every cab with where it is (at the workshop and at which stage,
 * or on the road), its drivers, km and next service, open problem reports, approvals waiting
 * for the owner and money due; plus what the fleet spent this month and year, per cab.
 */
class FleetDashboardService
{
    public function __construct(
        protected CustomerPortalService $portal,
        protected BillingService $billing,
        protected FleetApprovalService $approvals,
    ) {}

    public function build(Customer $fleet): array
    {
        $vehicles = Vehicle::with(['make:id,name', 'model:id,name', 'drivers' => fn ($q) => $q->where('is_active', true)->select('fleet_drivers.id', 'name', 'phone')])
            ->where('customer_id', $fleet->id)->where('is_active', true)->orderBy('registration_no')->get();
        $ids = $vehicles->pluck('id');

        $openJobs = JobCard::withoutGlobalScopes()->with(['vehicle:id,registration_no,make_id,model_id', 'vehicle.make:id,name', 'vehicle.model:id,name', 'package:id,name', 'fleetDriver:id,name'])
            ->where('customer_id', $fleet->id)->whereNotIn('status', CustomerPortalService::CLOSED)->latest('id')->get()
            ->keyBy('vehicle_id');
        $reports = FleetReport::where('customer_id', $fleet->id)->whereIn('status', [FleetReport::OPEN, FleetReport::BOOKED])
            ->selectRaw('vehicle_id, COUNT(*) c')->groupBy('vehicle_id')->pluck('c', 'vehicle_id');

        // Bills of jobs delivered this year, per cab
        $delivered = JobCard::withoutGlobalScopes()->where('customer_id', $fleet->id)->where('status', JobCardStatus::Delivered->value)
            ->where('status_changed_at', '>=', now()->startOfYear())->get(['id', 'vehicle_id', 'customer_id', 'status_changed_at']);
        $spend = ['month' => 0.0, 'year' => 0.0, 'per_vehicle' => []];
        foreach ($delivered as $job) {
            $total = (float) ($this->billing->summary($job)['bill_total'] ?? 0);
            $spend['year'] += $total;
            if ($job->status_changed_at?->gte(now()->startOfMonth())) {
                $spend['month'] += $total;
            }
            $spend['per_vehicle'][$job->vehicle_id] = ($spend['per_vehicle'][$job->vehicle_id] ?? 0) + $total;
        }

        $warnKm = (int) WorkshopSettings::get('fleet.service_due_km_warning');
        $retention = app(RetentionService::class);
        $cabs = $vehicles->map(function (Vehicle $v) use ($openJobs, $reports, $spend, $warnKm, $retention, $fleet) {
            $job = $openJobs->get($v->id);
            $card = $job ? $this->portal->jobCard($job) : null;
            $km = OdometerReading::latestFor($v->id);
            $kmLeft = $v->next_service_due_km && $km ? $v->next_service_due_km - $km->km : null;

            return [
                'id' => $v->id,
                'registration_no' => $v->registration_no,
                'car' => $this->portal->carName($v),
                'drivers' => collect($fleet->ownerDrives($v->id) ? [['id' => null, 'name' => $fleet->name . ' (owner)', 'phone' => $fleet->phone]] : [])
                    ->concat($v->drivers->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'phone' => $d->phone]))->values(),
                'state' => $job ? 'workshop' : 'on_road',
                'job' => $card ? $card + ['driver' => $job->fleetDriver?->name] : null,
                'km' => $km?->km,
                'km_at' => $km?->read_at?->toIso8601String(),
                'next_service_due_km' => $v->next_service_due_km,
                'km_to_service' => $kmLeft,
                'service_due_soon' => $kmLeft !== null && $kmLeft <= $warnKm,
                'next_service_due' => $retention->serviceDueDate($v)?->toDateString(),
                'open_reports' => (int) ($reports[$v->id] ?? 0),
                'spend_year' => round((float) ($spend['per_vehicle'][$v->id] ?? 0), 2),
            ];
        })->values();

        return [
            'fleet' => [
                'name' => $fleet->company_name ?: $fleet->name,
                'driver_limit' => $this->approvals->limitFor($fleet),
            ],
            'totals' => [
                'cabs' => $cabs->count(),
                'at_workshop' => $cabs->where('state', 'workshop')->count(),
                'approvals_waiting' => $cabs->sum(fn ($c) => $c['job']['approvals_pending'] ?? 0),
                'balance_due' => round($cabs->sum(fn ($c) => $c['job']['balance'] ?? 0), 2),
                'open_reports' => $cabs->sum('open_reports'),
                'service_due_soon' => $cabs->where('service_due_soon', true)->count(),
                'spend_month' => round($spend['month'], 2),
                'spend_year' => round($spend['year'], 2),
            ],
            'cabs' => $cabs,
        ];
    }
}
