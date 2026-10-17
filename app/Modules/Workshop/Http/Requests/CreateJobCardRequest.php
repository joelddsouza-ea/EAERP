<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Modules\Workshop\Http\Requests\Concerns\ValidatesServicePlan;
use App\Modules\Workshop\Models\JobCard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            // Drive-in, pickup & drop, or home service (the driver / home technician records odometer and fuel)
            'service_mode' => 'nullable|in:' . implode(',', JobCard::MODES),
            'km_reading' => 'required_if:service_mode,drive_in|required_without:service_mode|nullable|integer|min:0',
            'fuel_level' => 'required_if:service_mode,drive_in|required_without:service_mode|nullable|in:empty,quarter,half,three_quarter,full',
            // Pickup / home visit booking
            'trip' => 'exclude_if:service_mode,drive_in|exclude_without:service_mode|required|array',
            'trip.address' => 'required_with:trip|string|max:1000',
            'trip.landmark' => 'nullable|string|max:255',
            'trip.maps_url' => 'nullable|url|max:500',
            'trip.lat' => 'nullable|numeric|between:-90,90',
            'trip.lng' => 'nullable|numeric|between:-180,180',
            'trip.slot_date' => 'required_with:trip|date|after_or_equal:today',
            'trip.slot_from' => 'nullable|date_format:H:i',
            'trip.slot_to' => 'nullable|date_format:H:i|after:trip.slot_from',
            'trip.assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'trip.charge' => 'nullable|boolean',
            'trip.drop_charge' => 'nullable|boolean',
            'trip.save_address' => 'nullable|boolean',
            'inventory_items' => 'nullable|array',
            'customer_complaints' => 'nullable|string',
            // One line per complaint: each becomes an Additional Issues line on the job sheet
            'complaints' => 'nullable|array|max:30',
            'complaints.*' => 'nullable|string|max:1000',
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
            if ($this->input('service_mode') === JobCard::MODE_HOME && $this->integer('package_id')
                && !\App\Modules\Workshop\Models\ServicePackage::whereKey($this->integer('package_id'))->value('home_service_available')) {
                $validator->errors()->add('service_mode', 'This plan is not offered as a home service (see Service Plans).');
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
