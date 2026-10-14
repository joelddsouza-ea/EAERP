<?php

namespace App\Modules\Workshop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('workshop.vehicles.create');
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'make_id' => 'nullable|exists:vehicle_makes,id',
            'model_id' => 'nullable|exists:vehicle_models,id',
            'category_id' => 'nullable|exists:vehicle_categories,id',
            'registration_no' => 'nullable|string|max:20',
            'vin' => 'nullable|string|max:50',
            'year' => 'nullable|integer|min:1900|max:' . (date('Y') + 1),
            'color' => 'nullable|string|max:50',
            'fuel_type' => 'nullable|in:petrol,diesel,cng,electric,hybrid',
            'transmission' => 'nullable|in:manual,automatic',
            'engine_no' => 'nullable|string|max:50',
            'chassis_no' => 'nullable|string|max:50',
            'insurance_company' => 'nullable|string|max:100',
            'insurance_policy_no' => 'nullable|string|max:50',
            'insurance_expiry' => 'nullable|date',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
