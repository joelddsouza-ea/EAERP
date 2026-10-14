<?php

namespace App\Modules\Workshop\Services;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\JobCardChecklist;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\ChecklistTemplate;
use App\Modules\Workshop\Models\FollowUpCall;
use App\Core\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class JobCardService
{
    public function __construct(
        protected NotificationService $notificationService
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
            $data['status'] = $data['status'] ?? 'draft';

            // Calculate package price if package selected
            if (!empty($data['package_id']) && !empty($data['vehicle_id'])) {
                $data['package_price'] = $this->calculatePackagePrice(
                    $data['package_id'],
                    $data['vehicle_id'],
                    $data['oil_type_id'] ?? null
                );
            }

            $jobCard = JobCard::create($data);

            // Initialize checklists from templates
            $this->initializeChecklists($jobCard);

            // Log status change
            $this->logStatusChange($jobCard, null, $jobCard->status);

            return $jobCard->load(['customer', 'vehicle', 'package', 'oilType']);
        });
    }

    /**
     * Update job card.
     */
    public function updateJobCard(JobCard $jobCard, array $data): JobCard
    {
        return DB::transaction(function () use ($jobCard, $data) {
            $oldStatus = $jobCard->status;

            $jobCard->update($data);

            // Recalculate totals if pricing changed
            if (isset($data['package_id']) || isset($data['discount'])) {
                $jobCard->calculateTotals();
            }

            // Log status change if changed
            if (isset($data['status']) && $data['status'] !== $oldStatus) {
                $this->handleStatusChange($jobCard, $oldStatus, $data['status']);
            }

            return $jobCard->fresh()->load(['customer', 'vehicle', 'package', 'oilType']);
        });
    }

    /**
     * Change job card status.
     */
    public function changeStatus(JobCard $jobCard, string $newStatus, ?string $notes = null): JobCard
    {
        if (!$jobCard->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$jobCard->status} to {$newStatus}"
            );
        }

        $oldStatus = $jobCard->status;

        DB::transaction(function () use ($jobCard, $oldStatus, $newStatus, $notes) {
            $jobCard->status = $newStatus;

            // Set timestamps based on status
            switch ($newStatus) {
                case 'received':
                    $jobCard->time_in = $jobCard->time_in ?? now();
                    break;
                case 'in_progress':
                    $jobCard->work_started_at = now();
                    break;
                case 'qc':
                    $jobCard->work_completed_at = now();
                    break;
                case 'ready':
                    $jobCard->qc_completed_at = now();
                    break;
                case 'delivered':
                    $jobCard->time_out = now();
                    break;
            }

            $jobCard->save();

            $this->handleStatusChange($jobCard, $oldStatus, $newStatus, $notes);
        });

        return $jobCard->fresh();
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

        // Default to first oil type if not specified
        if (!$oilTypeId) {
            $oilTypeId = \App\Modules\Workshop\Models\OilType::first()?->id;
        }

        return $package->getPriceFor($vehicle->category_id, $oilTypeId) ?? 0;
    }

    /**
     * Initialize checklists from templates.
     */
    protected function initializeChecklists(JobCard $jobCard): void
    {
        // Get templates for this package
        $templates = ChecklistTemplate::where(function ($query) use ($jobCard) {
            $query->where('package_id', $jobCard->package_id)
                  ->orWhereNull('package_id'); // Generic templates
        })
        ->where('is_active', true)
        ->with(['sections.items'])
        ->get();

        foreach ($templates as $template) {
            $checklist = JobCardChecklist::create([
                'job_card_id' => $jobCard->id,
                'template_id' => $template->id,
                'status' => 'pending',
            ]);

            // Create checklist items
            foreach ($template->sections as $section) {
                foreach ($section->items as $item) {
                    if ($item->is_active) {
                        JobCardChecklistItem::create([
                            'job_card_checklist_id' => $checklist->id,
                            'checklist_item_id' => $item->id,
                            'is_completed' => false,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Handle status change side effects.
     */
    protected function handleStatusChange(
        JobCard $jobCard,
        ?string $fromStatus,
        string $toStatus,
        ?string $notes = null
    ): void {
        // Log status change
        $this->logStatusChange($jobCard, $fromStatus, $toStatus, $notes);

        // Send notifications based on status
        switch ($toStatus) {
            case 'received':
                // Notify customer
                $this->notificationService->notifyJobCardStatus(
                    $jobCard->customer,
                    $jobCard->job_number,
                    'received'
                );
                break;

            case 'in_progress':
                // Notify customer
                $this->notificationService->notifyJobCardStatus(
                    $jobCard->customer,
                    $jobCard->job_number,
                    'in_progress'
                );
                break;

            case 'ready':
                // Notify customer vehicle is ready
                $this->notificationService->notifyJobCardStatus(
                    $jobCard->customer,
                    $jobCard->job_number,
                    'ready'
                );
                break;

            case 'delivered':
                // Schedule follow-up calls
                $this->scheduleFollowUpCalls($jobCard);

                // Notify customer
                $this->notificationService->notifyJobCardStatus(
                    $jobCard->customer,
                    $jobCard->job_number,
                    'delivered'
                );
                break;
        }
    }

    /**
     * Log status change.
     */
    protected function logStatusChange(
        JobCard $jobCard,
        ?string $fromStatus,
        string $toStatus,
        ?string $notes = null
    ): void {
        JobCardStatusHistory::create([
            'job_card_id' => $jobCard->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'changed_by' => auth()->id(),
        ]);
    }

    /**
     * Schedule follow-up calls after delivery.
     */
    protected function scheduleFollowUpCalls(JobCard $jobCard): void
    {
        // 2-day courtesy call
        FollowUpCall::create([
            'job_card_id' => $jobCard->id,
            'customer_id' => $jobCard->customer_id,
            'call_type' => 'courtesy_2day',
            'status' => 'scheduled',
            'scheduled_date' => now()->addDays(2)->toDateString(),
        ]);

        // 7-day feedback call
        FollowUpCall::create([
            'job_card_id' => $jobCard->id,
            'customer_id' => $jobCard->customer_id,
            'call_type' => 'feedback',
            'status' => 'scheduled',
            'scheduled_date' => now()->addDays(7)->toDateString(),
        ]);
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
            'in_progress' => $jobs->where('status', 'in_progress')->count(),
            'pending_qc' => $jobs->where('status', 'qc')->count(),
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
