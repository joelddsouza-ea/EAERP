<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Core\Traits\HasAuditLog;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasAuditLog;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    /**
     * Roles relationship.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withTimestamps();
    }

    /**
     * Branches relationship - branches the user has access to.
     */
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get the user's primary branch.
     */
    public function primaryBranch()
    {
        return $this->branches()->wherePivot('is_primary', true)->first();
    }

    /**
     * Get all permissions for the user through their roles.
     */
    /**
     * Dashboard blocks across the user's roles (Roles & Access › Dashboard).
     *
     * @return string[]
     */
    public function dashboardBlocks(): array
    {
        $this->loadMissing('roles');
        $blocks = $this->roles->flatMap(fn (Role $role) => $role->dashboardBlocks())->unique()->all();

        return array_values(array_intersect(array_keys(Role::DASHBOARD_BLOCKS), $blocks));
    }

    public function getAllPermissions(): array
    {
        // Strict mode: roles may be loaded without their permissions
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn($role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->getAllPermissions());
    }

    /**
     * Check if user has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($permissions, $this->getAllPermissions()));
    }

    /**
     * Check if user has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return empty(array_diff($permissions, $this->getAllPermissions()));
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('slug', $role)->exists();
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('slug', $roles)->exists();
    }

    /**
     * Check if user is an Owner (has access to all branches).
     */
    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    /**
     * Check if user has access to a specific branch.
     */
    public function hasAccessToBranch(int $branchId): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    /**
     * Get IDs of branches the user has access to.
     */
    public function getAccessibleBranchIds(): array
    {
        if ($this->isOwner()) {
            return Branch::pluck('id')->toArray();
        }

        return $this->branches()->pluck('branches.id')->toArray();
    }

    /**
     * Scope to active users.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to users with a specific role.
     */
    public function scopeWithRole($query, string $role)
    {
        return $query->whereHas('roles', fn($q) => $q->where('slug', $role));
    }

    /**
     * Scope to users with access to a specific branch.
     */
    public function scopeForBranch($query, int $branchId)
    {
        return $query->whereHas('branches', fn($q) => $q->where('branches.id', $branchId));
    }
}
