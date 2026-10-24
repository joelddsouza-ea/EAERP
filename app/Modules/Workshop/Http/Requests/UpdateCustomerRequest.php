<?php

namespace App\Modules\Workshop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('workshop.customers.update');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'alt_phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'gstin' => 'nullable|string|max:20',
            'customer_type' => 'nullable|in:individual,corporate,fleet',
            'source' => 'nullable|in:walk_in,referral,online,lead,corporate,other',
            'company_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ];
    }
}
