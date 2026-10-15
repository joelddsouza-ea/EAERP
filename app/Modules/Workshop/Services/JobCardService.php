<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Enums\JobCardStatus;
use Illuminate\Support\Facades\DB;

class JobCardService
{
    public function __construct(
        protected JobSheetService $jobSheetService
    ) {}

    /**
     * Create a new job card.
     */
    public function createJobCard(array $data): JobCard
    {
        return DB::transaction(function () use ($data) {
            // Generate job number
            $data['job_number'] = JobCard::generateJobNumber($data['branch_id']);
            $data['time_in'] = $data['time_in'] ?? now();
            $data['service_advisor_id'] = $data['service_advisor_id'] ?? auth()->id();
            // Set explicitly: the DB default isn't loaded into the model, and logStatusChange needs it
            $data['status'] = $data['status'] ?? JobCardStatus::IntakePending->value;
            $data['status_changed_at'] = now();
            $data = $this->normalizeServiceType($data);

            // Calculate package price if package selected
            if (!empty($data['package_id']) && !empty($data['vehicle_id'])) {
                $data['package_price'] = $this->calculatePackagePrice(
                    $data['package_id'],
                    $data['vehicle_id'],
                    $data['oil_type_id'] ?? null
                );
            }

            $jobCard = JobCard::create($data);

            // Create the job sheets for this plan / service type
            $this->jobSheetService->instantiateFor($jobCard);

            JobCardStatusHistory::create([
                'job_card_id' => $jobCard->id,
                'from_status' => null,
                'to_status' => $jobCard->status,
                'changed_by' => auth()->id(),
            ]);

            return $jobCard->load(['customer', 'vehicle', 'package', 'oilType']);
        });
    }

    /**
     * Update job card.
     */
    public function updateJobCard(JobCard $jobCard, array $data): JobCard
    {
        // Status changes go through JobCardStatusService
        unset($data['status']);
        $data = $this->normalizeServiceType($data);

        return DB::transaction(function () use ($jobCard, $data) {
            $jobCard->fill($data);
            $planChanged = $jobCard->isDirty(['package_id', 'oil_type_id']);
            $sheetsChanged = $jobCard->isDirty(['package_id', 'service_type']);
            $sent = $jobCard->estimates()->where('status', '!=', JobCardEstimate::STATUS_DRAFT)->exists();

            // Once the customer has an estimate, the plan is changed there (new version) so the two never disagree
            if ($planChanged && $sent) {
                throw new EstimateException(
                    'An estimate has been sent to the customer. Change the plan or oil grade on the estimate instead.',
                    ['package_id' => ['Change the plan on the estimate (new version).']],
                );
            }

            if ($planChanged) {
                $jobCard->package_price = $jobCard->package_id
                    ? $this->calculatePackagePrice($jobCard->package_id, $jobCard->vehicle_id, $jobCard->oil_type_id)
                    : 0;
            }

            $jobCard->save();

            if ($planChanged || isset($data['discount'])) {
                $jobCard->calculateTotals();
            }

            // A plan or service-type change may bring in different job sheets; any save also adds
            // sheets the job should have but lacks (e.g. a plan set before sheets followed it)
            if ($sheetsChanged) {
                $this->jobSheetService->sync($jobCard);
            } else {
                $this->jobSheetService->instantiateFor($jobCard);
            }

            // Keep an open draft estimate on the same plan
            if ($planChanged) {
                $draft = $jobCard->estimates()->where('status', JobCardEstimate::STATUS_DRAFT)->latest('version')->first();
                if ($draft) {
                    // Resolved here to keep construction simple: EstimateService has a deep dependency chain
                    app(EstimateService::class)->refreshPlan($draft->setRelation('jobCard', $jobCard->fresh()));
                }
            }

            return $jobCard->fresh()->load(['customer', 'vehicle', 'package', 'oilType']);
        });
    }

    /**
     * A job card is a service plan ("scheduled" + package) or one repair type, which has no plan.
     */
    protected function normalizeServiceType(array $data): array
    {
        if (isset($data['service_type']) && $data['service_type'] !== 'scheduled') {
            $data['package_id'] = null;
        }

        return $data;
    }

    /**
     * Assign technician to job card.
     */
    public function assignTechnician(JobCard $jobCard, int $technicianId): JobCard
    {
        $jobCard->update(['technician_id' => $technicianId]);

        // Notify technician
        // $this->notificationService->sendPush(...);

        return $jobCard->fresh();
    }

    /**
     * Assign bay to job card.
     */
    public function assignBay(JobCard $jobCard, int $bayId): JobCard
    {
        $jobCard->update(['bay_id' => $bayId]);

        return $jobCard->fresh();
    }

    /**
     * Calculate package price.
     */
    protected function calculatePackagePrice(int $packageId, int $vehicleId, ?int $oilTypeId): float
    {
        $vehicle = \App\Modules\Workshop\Models\Vehicle::with('category')->find($vehicleId);

        if (!$vehicle || !$vehicle->category_id) {
            return 0;
        }

        $package = \App\Modules\Workshop\Models\ServicePackage::find($packageId);

        if (!$package) {
            return 0;
        }

        // No oil grade chosen: the first grade the plan is offered with for this car type
        if (!$oilTypeId) {
            return (float) ($package->pricing()
                ->where('category_id', $vehicle->category_id)
                ->join('oil_types', 'oil_types.id', '=', 'package_pricing.oil_type_id')
                ->orderBy('oil_types.sort_order')
                ->value('package_pricing.price') ?? 0);
        }

        return $package->getPriceFor($vehicle->category_id, $oilTypeId) ?? 0;
    }

    /**
     * Get technician workload.
     */
    public function getTechnicianWorkload(int $technicianId): array
    {
        $jobs = JobCard::where('technician_id', $technicianId)
            ->active()
            ->get();

        return [
            'total_jobs' => $jobs->count(),
            'in_progress' => $jobs->where('status', JobCardStatus::WorkInProgress->value)->count(),
            'pending_qc' => $jobs->where('status', JobCardStatus::PostWorkTrial->value)->count(),
            'jobs' => $jobs,
        ];
    }

    /**
     * Get bay status.
     */
    public function getBayStatus(int $branchId): array
    {
        $bays = \App\Modules\Workshop\Models\Bay::where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['currentJobCards.vehicle', 'currentJobCards.technician'])
            ->get();

        return $bays->map(function ($bay) {
            return [
                'id' => $bay->id,
                'name' => $bay->name,
                'code' => $bay->code,
                'type' => $bay->type,
                'is_occupied' => $bay->isOccupied(),
                'current_job' => $bay->getCurrentJobCard(),
            ];
        })->toArray();
    }
}
