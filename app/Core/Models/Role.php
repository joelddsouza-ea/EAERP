<?php

namespace App\Core\Models;

class Role extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * Default system roles.
     */
    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const SERVICE_ADVISOR = 'service-advisor';
    public const TECHNICIAN = 'technician';
    public const BACKOFFICE = 'backoffice';
    public const MARKETING = 'marketing';
    public const BODY_SHOP_MANAGER = 'body-shop-manager';
    public const BODY_SHOP_ADVISOR = 'body-shop-advisor';
    public const BODY_SHOP_TECHNICIAN = 'body-shop-technician';
    public const SPARE_PARTS_COORDINATOR = 'spare-parts-coordinator';
    public const DRIVER = 'driver';

    /**
     * Users relationship.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withTimestamps();
    }

    /**
     * Permissions relationship.
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * Assign permissions to the role.
     */
    public function assignPermissions(array $permissionSlugs): void
    {
        $permissionIds = Permission::whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->toArray();

        $this->permissions()->syncWithoutDetaching($permissionIds);
    }

    /**
     * Revoke permissions from the role.
     */
    public function revokePermissions(array $permissionSlugs): void
    {
        $permissionIds = Permission::whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->toArray();

        $this->permissions()->detach($permissionIds);
    }

    /**
     * Sync permissions for the role.
     */
    public function syncPermissions(array $permissionSlugs): void
    {
        $permissionIds = Permission::whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->toArray();

        $this->permissions()->sync($permissionIds);
    }

    /**
     * Check if role has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()->where('slug', $permission)->exists();
    }

    /**
     * Scope to system roles.
     */
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope to custom roles.
     */
    public function scopeCustom($query)
    {
        return $query->where('is_system', false);
    }
}
