<?php

namespace App\Modules\UserAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('user-admin.branches.update');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $branchId = $this->route('branch')->id;

        return [
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                'max:10',
                Rule::unique('branches', 'code')->ignore($branchId),
            ],
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'gstin' => 'nullable|string|max:20',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'geofence_radius_m' => 'sometimes|integer|min:20|max:5000',
            'weekly_off_day' => 'sometimes|integer|between:0,6',
            'is_active' => 'boolean',
        ];
    }
}
