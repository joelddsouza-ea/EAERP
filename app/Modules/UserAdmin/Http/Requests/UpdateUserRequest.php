<?php

namespace App\Modules\UserAdmin\Http\Requests;

use App\Core\Rules\IndianMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('user-admin.users.update');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'nullable',
                'email',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'phone' => [
                'nullable',
                'string',
                new IndianMobile(),
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'roles' => 'sometimes|array|min:1',
            'roles.*' => 'string|exists:roles,slug',
            'branches' => 'sometimes|array|min:1',
            'branches.*' => 'integer|exists:branches,id',
            'primary_branch_id' => 'sometimes|integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('branches') && $this->has('primary_branch_id')) {
                if (!in_array($this->primary_branch_id, $this->branches)) {
                    $validator->errors()->add(
                        'primary_branch_id',
                        'Primary branch must be one of the assigned branches.'
                    );
                }
            }
        });
    }

    /**
     * Mobile numbers are kept as 10 digits (+91 / 0 / spaces removed).
     */
    protected function prepareForValidation(): void
    {
        foreach (['phone'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => IndianMobile::clean($this->input($field))]);
            }
        }
    }
}
