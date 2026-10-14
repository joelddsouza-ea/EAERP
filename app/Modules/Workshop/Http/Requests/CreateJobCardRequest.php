<?php

namespace App\Modules\Workshop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateJobCardRequest extends FormRequest
{
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
            'service_type' => 'nullable|in:scheduled,general_repair,ac_service,body_shop,electrical,detailing,home_service',
            'km_reading' => 'nullable|integer|min:0',
            'fuel_level' => 'nullable|in:empty,quarter,half,three_quarter,full',
            'inventory_items' => 'nullable|array',
            'customer_complaints' => 'nullable|string',
            'advisor_observations' => 'nullable|string',
            'estimated_delivery' => 'nullable|date',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'discount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            'internal_notes' => 'nullable|string',
        ];
    }
}
