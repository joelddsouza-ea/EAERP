<?php

namespace App\Modules\CustomerApp\Services;

use App\Core\Support\WorkshopSettings;
use App\Modules\Billing\Models\CustomerCredit;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\CustomerApp\Models\CustomerNotification;
use App\Modules\CustomerApp\Models\PaymentClaim;
use App\Modules\CustomerApp\Support\AppViewer;
use App\Modules\Fleet\Services\FleetApprovalService;
use App\Modules\Hr\Models\StaffLiveStatus;
use App\Modules\Hr\Support\Geo;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardEstimateLine;
use App\Modules\Workshop\Models\JobCardObservation;
use App\Modules\Workshop\Models\JobTrip;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Services\CustomerLinkService;
use App\Modules\Workshop\Services\EstimateDecisionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * What the customer sees in the app: their cars, jobs in progress (opened through the same
 * job page as the WhatsApp link), each car's service book, and the driver on the way.
 * Everything is limited to the signed-in customer's own records.
 */
class CustomerPortalService
{
    public const CLOSED = ['delivered', 'cancelled'];

    public function __construct(
        protected CustomerLinkService $links,
        protected BillingService $billing,
        protected EstimateDecisionService $decisions,
    ) {}

    public function home(Customer $customer, ?AppViewer $viewer = null): array
    {
        $viewer ??= new AppViewer($customer);
        $vehicles = $viewer->scope(Vehicle::with(['make:id,name', 'model:id,name'])->where('customer_id', $customer->id)->where('is_active', true), 'id')->orderBy('id')->get();
        $jobs = $viewer->scope(JobCard::withoutGlobalScopes()->with(['vehicle:id,registration_no,make_id,model_id', 'vehicle.make:id,name', 'vehicle.model:id,name', 'package:id,name'])
            ->where('customer_id', $customer->id)->whereNotIn('status', self::CLOSED))->latest('id')->get();

        return [
            'customer' => ['id' => $customer->id, 'name' => $viewer->driver?->name ?? $customer->name, 'phone' => $viewer->driver?->phone ?? $customer->phone],
            'viewer' => $viewer->present(),
            'active_jobs' => $jobs->map(fn (JobCard $j) => $this->jobCard($j, $viewer))->values(),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => $this->vehicleCard($v))->values(),
            'unread' => CustomerNotification::where('customer_id', $customer->id)->where('recipient_key', (int) $viewer->driverId())->whereNull('read_at')->count(),
            'credit' => $viewer->isDriver() ? 0 : CustomerCredit::balanceFor($customer->id),
            'support_phone' => WorkshopSettings::get('customer_app.support_phone'),
        ];
    }

    public function jobCard(JobCard $job, ?AppViewer $viewer = null): array
    {
        $summary = $this->billing->summary($job);
        $estimate = JobCardEstimate::where('job_card_id', $job->id)->where('status', JobCardEstimate::STATUS_SENT)->latest('version')->first(['id']);
        $pending = $estimate && $this->decisions->decisionsOpen($job)
            ? JobCardEstimateLine::where('estimate_id', $estimate->id)->where('approval_status', JobCardEstimateLine::PENDING)->count()
            : 0;

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'status' => $job->status,
            'status_label' => JobCardStatus::tryFrom($job->status)?->label() ?? $job->status,
            'car' => $this->carName($job->vehicle),
            'registration_no' => $job->vehicle?->registration_no,
            'plan' => $job->package?->name,
            'created_at' => $job->created_at?->toIso8601String(),
            'approvals_pending' => $pending,
            'balance' => $summary['bill_total'] !== null ? max(0, (float) $summary['balance']) : null,
            'driver_on_the_way' => JobTrip::where('job_card_id', $job->id)->where('status', JobTrip::EN_ROUTE)->exists(),
            // A fleet driver: what they may still approve on this job
            'driver_limit_left' => $viewer?->isDriver() ? app(FleetApprovalService::class)->remaining($job) : null,
        ];
    }

    public function vehicleCard(Vehicle $v): array
    {
        $due = app(RetentionService::class)->serviceDueDate($v);

        return [
            'id' => $v->id,
            'car' => $this->carName($v),
            'registration_no' => $v->registration_no,
            'fuel_type' => $v->fuel_type,
            'year' => $v->year,
            'next_service_due' => $due?->toDateString(),
            'next_service_due_km' => $v->next_service_due_km,
            'insurance_expiry' => $v->insurance_expiry?->toDateString(),
            'puc_expiry' => $v->puc_expiry?->toDateString(),
        ];
    }

    public function carName(?Vehicle $v): string
    {
        return $v ? trim(($v->make?->name ?? '') . ' ' . ($v->model?->name ?? '')) ?: 'Car' : 'Car';
    }

    /**
     * The customer's job, or 404 (never another customer's).
     */
    public function job(Customer $customer, int $jobId, ?AppViewer $viewer = null): JobCard
    {
        $job = JobCard::withoutGlobalScopes()->where('customer_id', $customer->id)->find($jobId);
        if (!$job || ($viewer && !$viewer->canSeeVehicle($job->vehicle_id))) {
            throw (new ModelNotFoundException())->setModel(JobCard::class, [$jobId]);
        }

        return $job;
    }

    public function vehicle(Customer $customer, int $vehicleId, ?AppViewer $viewer = null): Vehicle
    {
        if ($viewer && !$viewer->canSeeVehicle($vehicleId)) {
            throw (new ModelNotFoundException())->setModel(Vehicle::class, [$vehicleId]);
        }

        return Vehicle::with(['make:id,name', 'model:id,name'])->where('customer_id', $customer->id)->findOrFail($vehicleId);
    }

    /**
     * One job in the app: its page token (the job page renders it), payment state and the live driver.
     */
    public function jobScreen(Customer $customer, int $jobId, ?AppViewer $viewer = null): array
    {
        $job = $this->job($customer, $jobId, $viewer);
        $job->loadMissing(['vehicle.make', 'vehicle.model', 'package']);
        // Past jobs too: the link is renewed when the customer opens it from the app.
        // A driver gets their own link: approvals on it are held to the fleet's limit
        $link = $this->links->ensure($job, null, $viewer?->driverId());
        $summary = $this->billing->summary($job);

        return [
            'job' => $this->jobCard($job, $viewer),
            'page_token' => $link->token ?? null,
            'payment' => [
                'bill_total' => $summary['bill_total'],
                'paid' => $summary['paid'],
                'balance' => $summary['bill_total'] !== null ? max(0, (float) $summary['balance']) : null,
                'upi_id' => WorkshopSettings::get('customer_app.upi_id'),
                'upi_name' => WorkshopSettings::get('customer_app.upi_name') ?: config('app.name'),
                'claims' => PaymentClaim::where('job_card_id', $job->id)->latest('id')->limit(10)->get()
                    ->map(fn (PaymentClaim $c) => app(PaymentClaimService::class)->present($c))->values(),
            ],
            'driver' => $this->liveDriver($job),
        ];
    }

    /**
     * The driver / home technician on the way: their last position while the trip is en route
     * and the phone has sent one in the last 10 minutes.
     */
    public function liveDriver(JobCard $job): ?array
    {
        $trip = JobTrip::with('assignee:id,name,phone')->where('job_card_id', $job->id)->where('status', JobTrip::EN_ROUTE)->latest('id')->first();
        if (!$trip || !$trip->assignee) {
            return null;
        }

        $status = StaffLiveStatus::where('user_id', $trip->assigned_to)->where('state', StaffLiveStatus::ACTIVE)->first();
        $fresh = $status && $status->recorded_at && $status->recorded_at->gt(now()->subMinutes(10)) && $status->lat !== null;
        $distance = $fresh && $trip->lat !== null && $trip->lng !== null ? Geo::distance($status->lat, $status->lng, (float) $trip->lat, (float) $trip->lng) : null;

        return [
            'kind' => $trip->kind,
            'label' => $trip->label(),
            'name' => strtok((string) $trip->assignee->name, ' '),
            'phone' => $trip->assignee->phone,
            'lat' => $fresh ? $status->lat : null,
            'lng' => $fresh ? $status->lng : null,
            'updated_at' => $fresh ? $status->recorded_at->toIso8601String() : null,
            'destination' => $trip->lat !== null ? ['lat' => (float) $trip->lat, 'lng' => (float) $trip->lng] : null,
            'distance_km' => $distance !== null ? round($distance / 1000, 1) : null,
            // City driving: about 25 km/h on the straight-line distance, rounded up
            'eta_minutes' => $distance !== null ? max(1, (int) ceil(($distance * 1.3 / 1000) / 25 * 60)) : null,
        ];
    }

    /**
     * Every visit of one car: delivered jobs with their documents, open observations, due dates.
     */
    public function serviceBook(Customer $customer, int $vehicleId, ?AppViewer $viewer = null): array
    {
        $vehicle = $this->vehicle($customer, $vehicleId, $viewer);
        $jobs = JobCard::withoutGlobalScopes()->with('package:id,name')
            ->where('vehicle_id', $vehicle->id)->where('customer_id', $customer->id)->where('status', JobCardStatus::Delivered->value)
            ->latest('id')->limit(50)->get();

        $visits = $jobs->map(function (JobCard $j) {
            $summary = $this->billing->summary($j);

            return [
                'job_id' => $j->id,
                'job_number' => $j->job_number,
                'date' => ($j->status_changed_at ?? $j->updated_at)?->toDateString(),
                'km' => $j->km_reading,
                'work' => $j->package?->name ?? ($j->service_type ? ucwords(str_replace('_', ' ', $j->service_type)) : 'Service'),
                // The job page (opened from here) shows the report, bill and photos
                'amount' => $summary['bill_total'],
            ];
        })->values();

        // Work recommended on earlier visits (declined or deferred), latest first
        $open = JobCardObservation::with('remark')->whereIn('job_card_id', $jobs->pluck('id'))->where('customer_visible', true)
            ->latest('id')->limit(20)->get()
            ->map(fn (JobCardObservation $o) => ['text' => $o->text, 'remark' => $o->remark?->label, 'severity' => $o->severity, 'date' => $o->created_at?->toDateString()])
            ->values();

        return [
            'vehicle' => $this->vehicleCard($vehicle) + [
                'color' => $vehicle->color,
                'transmission' => $vehicle->transmission,
                'last_service_at' => $vehicle->last_service_at?->toDateString(),
                'last_service_km' => $vehicle->last_service_km,
                'insurance_company' => $vehicle->insurance_company,
            ],
            'visits' => $visits,
            'observations' => $open,
            'total_spent' => round((float) $visits->sum('amount'), 2),
        ];
    }
}
