<?php

namespace App\Modules\UserAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('user-admin.users.create');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // Staff without an email sign in with their phone number
            'email' => 'required_without:phone|nullable|email|unique:users,email',
            'phone' => 'required_without:email|nullable|string|max:20|unique:users,phone',
            'password' => 'required|string|min:8|confirmed',
            'roles' => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,slug',
            'branches' => 'required|array|min:1',
            'branches.*' => 'integer|exists:branches,id',
            'primary_branch_id' => 'required|integer|in_array:branches.*',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'primary_branch_id.in_array' => 'Primary branch must be one of the assigned branches.',
        ];
    }
}
