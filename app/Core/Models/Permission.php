<?php

namespace App\Core\Models;

class Permission extends BaseModel
{
    /**
     * Disable soft deletes for permissions.
     */
    use \Illuminate\Database\Eloquent\SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'module',
        'description',
    ];

    /**
     * Roles relationship.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * Scope to permissions for a specific module.
     */
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Create permission slug from module and action.
     */
    public static function makeSlug(string $module, string $resource, string $action): string
    {
        return "{$module}.{$resource}.{$action}";
    }

    /**
     * Parse permission slug into components.
     */
    public static function parseSlug(string $slug): array
    {
        $parts = explode('.', $slug);

        return [
            'module' => $parts[0] ?? '',
            'resource' => $parts[1] ?? '',
            'action' => $parts[2] ?? '',
        ];
    }
}
