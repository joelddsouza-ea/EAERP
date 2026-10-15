<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Http\Requests\Concerns\ValidatesServicePlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateJobCardRequest extends FormRequest
{
    use ValidatesServicePlan;

    public function authorize(): bool
    {
        return $this->user()->hasPermission('workshop.job-cards.create');
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'vehicle_id' => 'required|exists:vehicles,id',
            'package_id' => 'nullable|exists:service_packages,id',
            'oil_type_id' => 'nullable|exists:oil_types,id',
            'bay_id' => 'nullable|exists:bays,id',
            'technician_id' => 'nullable|exists:users,id',
            'service_type' => 'required|in:' . self::SERVICE_TYPES,
            'km_reading' => 'nullable|integer|min:0',
            'fuel_level' => 'nullable|in:empty,quarter,half,three_quarter,full',
            'inventory_items' => 'nullable|array',
            'customer_complaints' => 'nullable|string',
            'advisor_observations' => 'nullable|string',
            'estimated_delivery' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            'internal_notes' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $this->checkServicePlan($validator, [
                'service_type' => $this->input('service_type'),
                'package_id' => $this->integer('package_id') ?: null,
                'oil_type_id' => $this->integer('oil_type_id') ?: null,
                'vehicle_id' => $this->integer('vehicle_id') ?: null,
            ]);
        });
    }
}
