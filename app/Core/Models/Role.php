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
        // Roles & Access › Dashboard: blocks this role sees (null = defaults)
        'dashboard_blocks',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_system' => 'boolean',
        'dashboard_blocks' => 'array',
    ];

    /**
     * Dashboard blocks, in the order shown.
     */
    public const DASHBOARD_BLOCKS = [
        'quick_actions' => 'Quick action buttons',
        'jobs_in_progress' => 'Jobs in progress',
        'todays_collection' => "Today's collection",
        'billing_till_date' => 'Billing till date',
        'total_outstanding' => 'Total outstanding',
        'bill_queues' => 'Bills to make / approve (back office)',
        'crm' => 'CRM: leads, follow-ups, bookings',
        'money' => 'Money: cash, bank, profit, staff-held',
        'status_chart' => 'Jobs by stage (chart)',
        'recent_jobs' => 'Recent job cards',
        'recent_payments' => 'Recent payments',
    ];

    /**
     * What this role sees on the dashboard: as set in Roles & Access, or the default —
     * owner and managers everything, back office the money without CRM, everyone else their work.
     *
     * @return string[]
     */
    public function dashboardBlocks(): array
    {
        if (is_array($this->dashboard_blocks)) {
            return array_values(array_intersect(array_keys(self::DASHBOARD_BLOCKS), $this->dashboard_blocks));
        }

        return match ($this->slug) {
            self::OWNER, self::MANAGER, self::BODY_SHOP_MANAGER => array_keys(self::DASHBOARD_BLOCKS),
            self::BACKOFFICE => ['quick_actions', 'bill_queues', 'todays_collection', 'billing_till_date', 'total_outstanding', 'money', 'recent_payments'],
            self::MARKETING => ['quick_actions', 'crm'],
            default => ['quick_actions', 'jobs_in_progress', 'recent_jobs'],
        };
    }

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
    // Staff with no other role: attendance, leave and payslips only
    public const EMPLOYEE = 'employee';
    // Outside washing / wheel-alignment partner: sees only the cars sent to them
    public const SERVICE_PARTNER = 'service-partner';

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
