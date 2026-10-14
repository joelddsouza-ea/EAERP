<?php

namespace App\Modules\UserAdmin\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn() =>
                $this->roles->map(fn($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                ])
            ),
            'branches' => $this->whenLoaded('branches', fn() =>
                $this->branches->map(fn($branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'is_primary' => (bool) $branch->pivot->is_primary,
                ])
            ),
            'primary_branch_id' => $this->whenLoaded('branches', fn() =>
                $this->branches->firstWhere('pivot.is_primary', true)?->id
            ),
        ];
    }
}
