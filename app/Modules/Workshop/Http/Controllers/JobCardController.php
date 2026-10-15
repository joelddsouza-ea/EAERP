<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Core\Services\FileStorageService;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardItem;
use App\Modules\Workshop\Models\JobCardAddon;
use App\Modules\Workshop\Models\JobCardPhoto;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\SparePartRequest;
use App\Modules\Workshop\Models\QcSignoff;
use App\Modules\Workshop\Models\FollowUpCall;
use App\Modules\Workshop\Http\Requests\CreateJobCardRequest;
use App\Modules\Workshop\Http\Requests\UpdateJobCardRequest;
use App\Modules\Workshop\Http\Resources\JobCardResource;
use App\Modules\Workshop\Services\JobCardService;
use App\Modules\Workshop\Services\JobCardStatusService;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Workflow\JobCardWorkflow;
use App\Modules\Workshop\Workflow\TransitionBlockedException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobCardController extends ApiController
{
    public function __construct(
        protected JobCardService $jobCardService,
        protected FileStorageService $fileStorage,
        protected JobCardStatusService $statusService,
        protected JobCardWorkflow $workflow
    ) {}

    /**
     * List job cards.
     */
    public function index(Request $request): JsonResponse
    {
        $query = JobCard::with(['customer', 'vehicle.make', 'vehicle.model', 'package', 'technician', 'bay'])
            ->when($request->status, fn($q, $status) =>
                $q->whereIn('status', (array) $status)
            )
            // My Jobs: the lead technician or a co-technician
            ->when($request->technician_id, fn($q, $id) =>
                $q->where(fn ($w) => $w->where('technician_id', $id)->orWhereHas('technicians', fn ($t) => $t->whereKey($id)))
            )
            ->when($request->service_type, fn($q, $type) =>
                $q->where('service_type', $type)
            )
            ->when($request->date, fn($q, $date) =>
                $q->whereDate('created_at', $date)
            )
            ->when($request->date_from, fn($q, $date) =>
                $q->whereDate('created_at', '>=', $date)
            )
            ->when($request->date_to, fn($q, $date) =>
                $q->whereDate('created_at', '<=', $date)
            )
            ->when($request->search, fn($q, $search) =>
                $q->where(fn($q) =>
                    $q->where('job_number', 'like', "%{$search}%")
                      ->orWhereHas('customer', fn($q) => $q->where('name', 'like', "%{$search}%"))
                      ->orWhereHas('vehicle', fn($q) => $q->where('registration_no', 'like', "%{$search}%"))
                )
            )
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_order ?? 'desc');

        $jobCards = $query->paginate($request->per_page ?? 15);

        return $this->paginated($jobCards);
    }

    /**
     * Create a new job card.
     */
    public function store(CreateJobCardRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['branch_id'] = app('branch.context')->getCurrentBranchId();

        $jobCard = $this->jobCardService->createJobCard($data);

        return $this->created(
            new JobCardResource($jobCard),
            'Job card created successfully'
        );
    }

    /**
     * Get a specific job card.
     */
    public function show(JobCard $jobCard): JsonResponse
    {
        $jobCard->load([
            'customer',
            'vehicle.make',
            'vehicle.model',
            'vehicle.category',
            'package',
            'oilType',
            'bay',
            'branch',
            'serviceAdvisor',
            'technician',
            'technicians:id,name',
            'items',
            'addons',
            'statusHistory.changedBy',
        ]);

        return $this->success(new JobCardResource($jobCard));
    }

    /**
     * Update a job card.
     */
    public function update(UpdateJobCardRequest $request, JobCard $jobCard): JsonResponse
    {
        $jobCard = $this->jobCardService->updateJobCard($jobCard, $request->validated());

        return $this->success(
            new JobCardResource($jobCard),
            'Job card updated successfully'
        );
    }

    /**
     * Delete a job card.
     */
    public function destroy(JobCard $jobCard): JsonResponse
    {
        if (!in_array($jobCard->status, [JobCardStatus::IntakePending->value, JobCardStatus::Cancelled->value], true)) {
            return $this->error('Only job cards awaiting intake or cancelled can be deleted', 400);
        }

        $jobCard->delete();

        return $this->success(null, 'Job card deleted successfully');
    }

    /**
     * Update job card status.
     */
    public function updateStatus(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::enum(JobCardStatus::class)],
            'notes' => 'nullable|string|max:500',
            'override' => 'sometimes|boolean',
        ]);

        try {
            $jobCard = $this->statusService->change(
                $jobCard,
                $request->status,
                $request->user(),
                $request->notes,
                $request->boolean('override')
            );
        } catch (TransitionBlockedException $e) {
            return $this->error($e->getMessage(), $e->status, $e->errors());
        }

        return $this->success(
            new JobCardResource($jobCard),
            'Status updated successfully'
        );
    }

    /**
     * Next statuses available from the current one, with any checks blocking them.
     */
    public function transitions(Request $request, JobCard $jobCard): JsonResponse
    {
        $stage = \App\Modules\Workshop\Models\WorkflowStage::for($jobCard->status);

        return $this->success([
            'options' => $this->workflow->transitionsFor($jobCard, $request->user()),
            // What the job is waiting for before it moves on by itself
            'stage' => [
                'status' => $jobCard->status,
                'waiting_for' => $stage?->waiting_for,
                'sent_back' => $this->workflow->enteredBackwards($jobCard),
            ],
        ]);
    }

    /**
     * Assign technician.
     */
    public function assignTechnician(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            // Only users holding a technician role
            'technician_id' => ['required', Rule::exists('user_roles', 'user_id')->whereIn('role_id',
                \App\Core\Models\Role::whereIn('slug', ['technician', 'body-shop-technician'])->pluck('id')->all())],
        ]);

        $jobCard = $this->jobCardService->assignTechnician($jobCard, $request->technician_id);

        return $this->success(
            new JobCardResource($jobCard->load('technician')),
            'Technician assigned successfully'
        );
    }

    /**
     * Assign bay.
     */
    public function assignBay(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'bay_id' => 'required|exists:bays,id',
        ]);

        $jobCard = $this->jobCardService->assignBay($jobCard, $request->bay_id);

        return $this->success(
            new JobCardResource($jobCard->load('bay')),
            'Bay assigned successfully'
        );
    }

    /**
     * Add item to job card.
     */
    public function addItem(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:labor,part,service,third_party',
            'description' => 'required|string|max:255',
            'part_number' => 'nullable|string|max:50',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:10',
            'unit_price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
        ]);

        $item = $jobCard->items()->create($request->all());

        return $this->created($item, 'Item added successfully');
    }

    /**
     * Update item.
     */
    public function updateItem(Request $request, JobCard $jobCard, JobCardItem $item): JsonResponse
    {
        $request->validate([
            'description' => 'sometimes|string|max:255',
            'quantity' => 'sometimes|numeric|min:0.01',
            'unit_price' => 'sometimes|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $item->update($request->all());

        return $this->success($item, 'Item updated successfully');
    }

    /**
     * Remove item.
     */
    public function removeItem(JobCard $jobCard, JobCardItem $item): JsonResponse
    {
        $item->delete();

        return $this->success(null, 'Item removed successfully');
    }

    /**
     * Add addon.
     */
    public function addAddon(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'addon_id' => 'nullable|exists:service_addons,id',
            'addon_name' => 'required|string|max:100',
            'price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
        ]);

        $data = $request->all();
        $data['final_price'] = ($data['price'] ?? 0) - ($data['discount'] ?? 0);

        $addon = $jobCard->addons()->create($data);

        return $this->created($addon, 'Addon added successfully');
    }

    /**
     * Remove addon.
     */
    public function removeAddon(JobCard $jobCard, JobCardAddon $addon): JsonResponse
    {
        $addon->delete();

        return $this->success(null, 'Addon removed successfully');
    }

    /**
     * Get checklists.
     */
    public function checklists(JobCard $jobCard): JsonResponse
    {
        $checklists = $jobCard->checklists()
            ->with(['template.sections.items', 'items.checklistItem', 'completedBy'])
            ->get();

        return $this->success($checklists);
    }

    /**
     * Update checklist item.
     */
    public function updateChecklistItem(
        Request $request,
        JobCard $jobCard,
        $checklistId,
        JobCardChecklistItem $item
    ): JsonResponse {
        $request->validate([
            'is_completed' => 'sometimes|boolean',
            'value' => 'nullable|string',
            'remarks' => 'nullable|string|max:500',
        ]);

        $item->update([
            'is_completed' => $request->is_completed ?? $item->is_completed,
            'value' => $request->value,
            'remarks' => $request->remarks,
            'completed_by' => $request->is_completed ? auth()->id() : null,
            'completed_at' => $request->is_completed ? now() : null,
        ]);

        return $this->success($item, 'Checklist item updated');
    }

    /**
     * Get photos.
     */
    public function photos(JobCard $jobCard): JsonResponse
    {
        $photos = $jobCard->photos()
            ->with('uploadedBy')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($photos);
    }

    /**
     * Upload photo.
     */
    public function uploadPhoto(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|max:5120', // 5MB max
            'photo_type' => 'required|in:before,after,parts,damage,customer_items,inspection,other',
            'caption' => 'nullable|string|max:255',
        ]);

        $fileInfo = $this->fileStorage->uploadJobCardPhoto(
            $request->file('photo'),
            $jobCard->id,
            $request->photo_type
        );

        $photo = $jobCard->photos()->create([
            'photo_type' => $request->photo_type,
            'file_path' => $fileInfo['path'],
            'caption' => $request->caption,
            'uploaded_by' => auth()->id(),
        ]);

        return $this->created($photo, 'Photo uploaded successfully');
    }

    /**
     * Delete photo.
     */
    public function deletePhoto(JobCard $jobCard, JobCardPhoto $photo): JsonResponse
    {
        $this->fileStorage->delete($photo->file_path, 'job_photos');
        $photo->delete();

        return $this->success(null, 'Photo deleted successfully');
    }

    /**
     * Get spare part requests.
     */
    public function spareRequests(JobCard $jobCard): JsonResponse
    {
        $requests = $jobCard->sparePartRequests()
            ->with(['requestedBy', 'approvedBy', 'issuedBy', 'receivedBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($requests);
    }

    /**
     * Create spare part request.
     */
    public function createSpareRequest(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'part_name' => 'required|string|max:255',
            'part_number' => 'nullable|string|max:50',
            'quantity' => 'required|numeric|min:0.01',
            'unit' => 'nullable|string|max:10',
            'remarks' => 'nullable|string',
        ]);

        $spareRequest = $jobCard->sparePartRequests()->create([
            ...$request->all(),
            'status' => 'requested',
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);

        return $this->created($spareRequest, 'Spare part request created');
    }

    /**
     * Update spare part request status.
     */
    public function updateSpareRequestStatus(
        Request $request,
        JobCard $jobCard,
        SparePartRequest $spareRequest
    ): JsonResponse {
        $request->validate([
            'status' => 'required|in:approved,declined,ordered,issued,received,returned',
            'quantity' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string',
            'rejection_reason' => 'nullable|string',
        ]);

        $updateData = ['status' => $request->status];

        switch ($request->status) {
            case 'approved':
                $updateData['approved_by'] = auth()->id();
                $updateData['approved_at'] = now();
                break;
            case 'declined':
                $updateData['rejection_reason'] = $request->rejection_reason;
                break;
            case 'issued':
                $updateData['issued_by'] = auth()->id();
                $updateData['issued_at'] = now();
                $updateData['issued_quantity'] = $request->quantity ?? $spareRequest->quantity;
                break;
            case 'received':
                $updateData['received_by'] = auth()->id();
                $updateData['received_at'] = now();
                $updateData['received_quantity'] = $request->quantity ?? $spareRequest->issued_quantity;
                break;
        }

        if ($request->remarks) {
            $updateData['remarks'] = $request->remarks;
        }

        $spareRequest->update($updateData);

        return $this->success($spareRequest->fresh(), 'Status updated');
    }

    /**
     * Get QC details.
     */
    public function qcDetails(JobCard $jobCard): JsonResponse
    {
        $signoffs = $jobCard->qcSignoffs()
            ->with('inspector')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($signoffs);
    }

    /**
     * QC signoff.
     */
    public function qcSignoff(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'phase' => 'required|in:pre_delivery,final',
            'passed' => 'required|boolean',
            'remarks' => 'nullable|string',
            'failed_items' => 'nullable|array',
        ]);

        $signoff = $jobCard->qcSignoffs()->create([
            'phase' => $request->phase,
            'inspector_id' => auth()->id(),
            'passed' => $request->passed,
            'remarks' => $request->remarks,
            'failed_items' => $request->failed_items,
            'inspected_at' => now(),
        ]);

        return $this->created($signoff, 'QC signoff recorded');
    }

    /**
     * Get follow-ups.
     */
    public function followUps(JobCard $jobCard): JsonResponse
    {
        $followUps = $jobCard->followUpCalls()
            ->with('calledBy')
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return $this->success($followUps);
    }

    /**
     * Create follow-up.
     */
    public function createFollowUp(Request $request, JobCard $jobCard): JsonResponse
    {
        $request->validate([
            'call_type' => 'required|in:courtesy_2day,feedback,next_service,complaint,other',
            'scheduled_date' => 'required|date',
            'scheduled_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string',
        ]);

        $followUp = $jobCard->followUpCalls()->create([
            'customer_id' => $jobCard->customer_id,
            'call_type' => $request->call_type,
            'status' => 'scheduled',
            'scheduled_date' => $request->scheduled_date,
            'scheduled_time' => $request->scheduled_time,
            'notes' => $request->notes,
        ]);

        return $this->created($followUp, 'Follow-up scheduled');
    }

    /**
     * Update follow-up.
     */
    public function updateFollowUp(Request $request, FollowUpCall $followUp): JsonResponse
    {
        $request->validate([
            'status' => 'sometimes|in:scheduled,completed,no_answer,rescheduled,cancelled',
            'scheduled_date' => 'sometimes|date',
            'rating' => 'nullable|integer|min:1|max:5',
            'feedback' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $updateData = $request->only(['status', 'scheduled_date', 'rating', 'feedback', 'notes']);

        if ($request->status === 'completed') {
            $updateData['called_by'] = auth()->id();
            $updateData['called_at'] = now();
        }

        $followUp->update($updateData);

        return $this->success($followUp->fresh(), 'Follow-up updated');
    }

    /**
     * Dashboard stats.
     */
    public function dashboardStats(): JsonResponse
    {
        $today = today();

        $stats = [
            'jobs_in_progress' => JobCard::active()->count(),
            'jobs_today' => JobCard::today()->count(),
            'pending_qc' => JobCard::status([JobCardStatus::PostWorkTrial->value, JobCardStatus::WashPending->value])->count(),
            'ready_for_delivery' => JobCard::status(JobCardStatus::ReadyForDelivery->value)->count(),
            'delivered_today' => JobCard::status('delivered')->whereDate('time_out', $today)->count(),
            'by_status' => JobCard::active()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
        ];

        return $this->success($stats);
    }

    /**
     * Technician workload.
     */
    public function technicianWorkload($technicianId): JsonResponse
    {
        $workload = $this->jobCardService->getTechnicianWorkload($technicianId);

        return $this->success($workload);
    }

    /**
     * Pending follow-ups.
     */
    public function pendingFollowUps(Request $request): JsonResponse
    {
        $followUps = FollowUpCall::pending()
            ->with(['customer', 'jobCard'])
            ->when($request->date, fn($q, $date) =>
                $q->whereDate('scheduled_date', $date)
            )
            ->when(!$request->date, fn($q) =>
                $q->where('scheduled_date', '<=', today()->addDays(7))
            )
            ->orderBy('scheduled_date')
            ->paginate($request->per_page ?? 20);

        return $this->paginated($followUps);
    }
}
