<?php

namespace App\Modules\Workshop\Http\Requests;

use App\Core\Rules\IndianMobile;
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
            'phone' => ['nullable', 'string', new IndianMobile()],
            'alt_phone' => ['nullable', 'string', new IndianMobile()],
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

    /**
     * Mobile numbers are kept as 10 digits (+91 / 0 / spaces removed).
     */
    protected function prepareForValidation(): void
    {
        foreach (['phone', 'alt_phone'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => IndianMobile::clean($this->input($field))]);
            }
        }
    }
}
