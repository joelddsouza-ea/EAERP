<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\Vehicle;
use App\Modules\Workshop\Http\Requests\CreateVehicleRequest;
use App\Modules\Workshop\Http\Requests\UpdateVehicleRequest;
use App\Modules\Workshop\Http\Resources\VehicleResource;
use App\Modules\Workshop\Http\Resources\JobCardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends ApiController
{
    /**
     * List all vehicles.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vehicle::with(['customer', 'make', 'model', 'category'])
            ->withCount('jobCards')
            ->quickSearch($request->search)
            ->when($request->customer_id, fn($q, $id) =>
                $q->where('customer_id', $id)
            )
            ->when($request->make_id, fn($q, $id) =>
                $q->where('make_id', $id)
            )
            ->when($request->category_id, fn($q, $id) =>
                $q->where('category_id', $id)
            )
            ->orderBy($request->sort_by ?? 'created_at', $request->sort_order ?? 'desc');

        $vehicles = $query->paginate($request->per_page ?? 15);

        return $this->paginated($vehicles);
    }

    /**
     * Create a new vehicle.
     */
    public function store(CreateVehicleRequest $request): JsonResponse
    {
        // Set default category from model if not provided
        $data = $request->validated();

        if (empty($data['category_id']) && !empty($data['model_id'])) {
            $model = \App\Modules\Workshop\Models\VehicleModel::find($data['model_id']);
            $data['category_id'] = $model?->default_category_id;
        }

        $vehicle = Vehicle::create($data);

        return $this->created(
            new VehicleResource($vehicle->load(['customer', 'make', 'model', 'category'])),
            'Vehicle created successfully'
        );
    }

    /**
     * Get a specific vehicle.
     */
    public function show(Vehicle $vehicle): JsonResponse
    {
        return $this->success(
            new VehicleResource($vehicle->load(['customer', 'make', 'model', 'category']))
        );
    }

    /**
     * Update a vehicle.
     */
    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle->update($request->validated());

        return $this->success(
            new VehicleResource($vehicle->load(['customer', 'make', 'model', 'category'])),
            'Vehicle updated successfully'
        );
    }

    /**
     * Delete a vehicle.
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->jobCards()->exists()) {
            return $this->error('Cannot delete vehicle with existing job cards', 400);
        }

        $vehicle->delete();

        return $this->success(null, 'Vehicle deleted successfully');
    }

    /**
     * Get vehicle service history.
     */
    public function serviceHistory(Request $request, Vehicle $vehicle): JsonResponse
    {
        $jobCards = $vehicle->jobCards()
            ->with(['package', 'branch', 'serviceAdvisor'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10);

        return $this->paginated($jobCards);
    }

    /**
     * Search vehicle by registration number.
     */
    public function searchByRegistration(Request $request): JsonResponse
    {
        $registration = $request->get('registration');

        if (empty($registration) || strlen($registration) < 3) {
            return $this->success([]);
        }

        $vehicles = Vehicle::where('registration_no', 'like', "%{$registration}%")
            ->with(['customer', 'make', 'model', 'category'])
            ->limit(10)
            ->get();

        return $this->success(VehicleResource::collection($vehicles));
    }
}
