<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardBayMove;
use App\Modules\Workshop\Models\JobCardEstimate;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Notifications\StaffAlert;
use App\Core\Models\User;
use Illuminate\Support\Facades\DB;

class JobCardService
{
    public function __construct(
        protected JobSheetService $jobSheetService,
        protected AdditionalIssueService $issueLines,
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
            // A technician chosen on the form goes through addTechnician (pivot + notification)
            $technicianId = $data['technician_id'] ?? null;
            unset($data['technician_id']);

            // Complaint lines also kept as one text for older screens and documents
            $complaints = array_values(array_filter(array_map('trim', $data['complaints'] ?? []), fn ($c) => $c !== ''));
            unset($data['complaints']);
            if ($complaints && empty($data['customer_complaints'])) {
                $data['customer_complaints'] = implode("\n", $complaints);
            }

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

            // Each complaint is a line under Additional Issues on the technician's sheet
            $this->issueLines->createComplaints($jobCard, $complaints, auth()->user());

            if ($technicianId) {
                $jobCard = $this->addTechnician($jobCard, (int) $technicianId, true);
            }

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
        $technicianId = $data['technician_id'] ?? null;
        unset($data['technician_id']);

        return DB::transaction(function () use ($jobCard, $data, $technicianId) {
            if ($technicianId && (int) $technicianId !== $jobCard->technician_id) {
                $jobCard = $this->addTechnician($jobCard, (int) $technicianId, true);
            }
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
        // Kept for the single-technician endpoint: makes this technician the lead
        return $this->addTechnician($jobCard, $technicianId, true);
    }

    /**
     * Put a technician on the job. The lead is also kept in job_cards.technician_id.
     */
    public function addTechnician(JobCard $jobCard, int $technicianId, bool $lead = false): JobCard
    {
        $alreadyOn = $jobCard->technician_id === $technicianId
            || $jobCard->technicians()->where('users.id', $technicianId)->exists();

        $jobCard = DB::transaction(function () use ($jobCard, $technicianId, $lead) {
            $makeLead = $lead || !$jobCard->technician_id;

            if ($makeLead) {
                DB::table('job_card_technicians')->where('job_card_id', $jobCard->id)->update(['is_lead' => false]);
            }

            $jobCard->technicians()->syncWithoutDetaching([
                $technicianId => ['is_lead' => $makeLead, 'assigned_by' => auth()->id(), 'assigned_at' => now()],
            ]);
            if (!$makeLead) {
                DB::table('job_card_technicians')
                    ->where(['job_card_id' => $jobCard->id, 'user_id' => $technicianId])
                    ->update(['is_lead' => false]);
            }

            if ($makeLead) {
                $jobCard->update(['technician_id' => $technicianId]);
            }

            return $jobCard->fresh();
        });

        // Tell the technician about a new job (not when someone already on it is only made lead)
        if (!$alreadyOn && $technicianId !== auth()->id()) {
            $jobCard->load('vehicle:id,registration_no,make_id,model_id', 'vehicle.make:id,name', 'vehicle.model:id,name');
            User::find($technicianId)?->notify(StaffAlert::jobAssigned($jobCard, auth()->user()?->name));
        }

        return $jobCard;
    }

    /**
     * Take a technician off the job; if it was the lead, the next one becomes lead.
     */
    public function removeTechnician(JobCard $jobCard, int $technicianId): JobCard
    {
        return DB::transaction(function () use ($jobCard, $technicianId) {
            $jobCard->technicians()->detach($technicianId);

            if ($jobCard->technician_id === $technicianId) {
                $next = DB::table('job_card_technicians')->where('job_card_id', $jobCard->id)->orderBy('id')->value('user_id');
                if ($next) {
                    DB::table('job_card_technicians')->where(['job_card_id' => $jobCard->id, 'user_id' => $next])->update(['is_lead' => true]);
                }
                $jobCard->update(['technician_id' => $next]);
            }

            return $jobCard->fresh();
        });
    }

    /**
     * Put the car in a bay, or move it to another bay (the move is kept in the history).
     */
    public function assignBay(JobCard $jobCard, int $bayId, ?string $notes = null): JobCard
    {
        if ($jobCard->bay_id === $bayId) {
            return $jobCard;
        }

        return DB::transaction(function () use ($jobCard, $bayId, $notes) {
            JobCardBayMove::create([
                'job_card_id' => $jobCard->id,
                'from_bay_id' => $jobCard->bay_id,
                'to_bay_id' => $bayId,
                'notes' => $notes,
                'moved_by' => auth()->id(),
                'moved_at' => now(),
            ]);

            $jobCard->update(['bay_id' => $bayId]);

            return $jobCard->fresh();
        });
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
