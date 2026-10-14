<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Core\Traits\HasAuditLog;

/**
 * Base Model for all application models
 *
 * Provides common functionality:
 * - UUID primary keys (optional)
 * - Soft deletes
 * - Audit logging
 * - Factory support
 */
abstract class BaseModel extends Model
{
    use HasFactory, SoftDeletes, HasAuditLog;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the default guard name for the model.
     */
    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    /**
     * Scope a query to only include active records.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to search by a column.
     */
    public function scopeSearch($query, string $column, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where($column, 'LIKE', "%{$term}%");
    }
}
