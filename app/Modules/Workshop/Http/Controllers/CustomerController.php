<?php

namespace App\Modules\Workshop\Http\Controllers;

use App\Core\Http\Controllers\Api\ApiController;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Http\Requests\CreateCustomerRequest;
use App\Modules\Workshop\Http\Requests\UpdateCustomerRequest;
use App\Modules\Workshop\Http\Resources\CustomerResource;
use App\Modules\Workshop\Http\Resources\VehicleResource;
use App\Modules\Workshop\Http\Resources\JobCardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    /**
     * List all customers.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Customer::with(['vehicles.make', 'vehicles.model', 'vehicles.category'])
            ->withCount(['vehicles', 'jobCards'])
            ->quickSearch($request->search)
            ->when($request->customer_type, fn($q, $type) =>
                $q->where('customer_type', $type)
            )
            ->when($request->has('is_active'), fn($q) =>
                $q->where('is_active', $request->boolean('is_active'))
            )
            ->orderBy($request->sort_by ?? 'name', $request->sort_order ?? 'asc');

        $customers = $query->paginate($request->per_page ?? 15);

        return $this->paginated($customers);
    }

    /**
     * Create a new customer.
     */
    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return $this->created(
            new CustomerResource($customer),
            'Customer created successfully'
        );
    }

    /**
     * Get a specific customer.
     */
    public function show(Customer $customer): JsonResponse
    {
        return $this->success(
            new CustomerResource($customer->load(['vehicles.make', 'vehicles.model', 'vehicles.category']))
        );
    }

    /**
     * Update a customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return $this->success(
            new CustomerResource($customer),
            'Customer updated successfully'
        );
    }

    /**
     * Delete a customer.
     */
    public function destroy(Customer $customer): JsonResponse
    {
        if ($customer->jobCards()->exists()) {
            return $this->error('Cannot delete customer with existing job cards', 400);
        }

        $customer->delete();

        return $this->success(null, 'Customer deleted successfully');
    }

    /**
     * Get customer's vehicles.
     */
    public function vehicles(Customer $customer): JsonResponse
    {
        $vehicles = $customer->vehicles()
            ->with(['make', 'model', 'category'])
            ->get();

        return $this->success(VehicleResource::collection($vehicles));
    }

    /**
     * Get customer's job cards.
     */
    public function jobCards(Request $request, Customer $customer): JsonResponse
    {
        $jobCards = $customer->jobCards()
            ->with(['vehicle', 'package', 'branch'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10);

        return $this->paginated($jobCards);
    }

    /**
     * Search customer by phone.
     */
    public function searchByPhone(Request $request): JsonResponse
    {
        $phone = $request->get('phone');

        if (empty($phone) || strlen($phone) < 4) {
            return $this->success([]);
        }

        $customers = Customer::where('phone', 'like', "%{$phone}%")
            ->orWhere('alt_phone', 'like', "%{$phone}%")
            ->with(['vehicles.make', 'vehicles.model', 'vehicles.category'])
            ->limit(10)
            ->get();

        return $this->success(CustomerResource::collection($customers));
    }
}
