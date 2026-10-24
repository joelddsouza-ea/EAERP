<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Http\Requests\Concerns\ValidatesServicePlan;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateJobCardRequest extends FormRequest
{
    use ValidatesServicePlan;

    public function authorize(): bool
    {
        return $this->user()->hasPermission('workshop.job-cards.update');
    }

    public function rules(): array
    {
        return [
            'package_id' => 'nullable|exists:service_packages,id',
            'oil_type_id' => 'nullable|exists:oil_types,id',
            'bay_id' => 'nullable|exists:bays,id',
            'technician_id' => 'nullable|exists:users,id',
            'service_type' => 'sometimes|required|in:' . self::SERVICE_TYPES,
            'km_reading' => 'nullable|integer|min:0',
            'fuel_level' => 'nullable|in:empty,quarter,half,three_quarter,full',
            'inventory_items' => 'nullable|array',
            'customer_complaints' => 'nullable|string',
            'advisor_observations' => 'nullable|string',
            'estimated_delivery' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'discount_reason' => 'nullable|string|max:255',
            'internal_notes' => 'nullable|string',
            'customer_approved' => 'nullable|boolean',
            'show_customer_timeline' => 'sometimes|boolean',
            // A fleet cab: the driver who brought it in
            'fleet_driver_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('fleet_drivers', 'id')->where('customer_id', (int) $this->route('jobCard')?->customer_id)],
        ];
    }

    /**
     * Check the plan rules against the job card as it will be after this update.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $touched = $this->hasAny(['service_type', 'package_id', 'oil_type_id']);
            if (!$touched || $validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var JobCard $jobCard */
            $jobCard = $this->route('jobCard');

            $pick = fn (string $field) => $this->exists($field)
                ? ($this->input($field) ?: null)
                : $jobCard->{$field};

            $this->checkServicePlan($validator, [
                'service_type' => $pick('service_type'),
                'package_id' => $pick('package_id') ? (int) $pick('package_id') : null,
                'oil_type_id' => $pick('oil_type_id') ? (int) $pick('oil_type_id') : null,
                'vehicle_id' => $jobCard->vehicle_id,
            ]);
        });
    }
}
