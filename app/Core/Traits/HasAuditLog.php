<?php

namespace App\Core\Traits;

use App\Core\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Trait for automatic audit logging on model changes.
 */
trait HasAuditLog
{
    /**
     * Boot the trait.
     */
    public static function bootHasAuditLog(): void
    {
        static::created(function ($model) {
            $model->logAudit('created');
        });

        static::updated(function ($model) {
            if ($model->wasChanged()) {
                $model->logAudit('updated');
            }
        });

        static::deleted(function ($model) {
            $model->logAudit('deleted');
        });
    }

    /**
     * Log an audit entry.
     */
    protected function logAudit(string $action): void
    {
        // Skip if audit logging is disabled
        if (property_exists($this, 'disableAuditLog') && $this->disableAuditLog) {
            return;
        }

        // Skip for audit_logs table itself to prevent recursion
        if ($this->getTable() === 'audit_logs') {
            return;
        }

        $userId = Auth::id();
        $oldValues = $action === 'updated' ? $this->getOriginal() : null;
        $newValues = $action === 'deleted' ? null : $this->getAttributes();

        // Remove sensitive fields
        $sensitiveFields = ['password', 'remember_token', 'api_token'];
        if (is_array($oldValues)) {
            $oldValues = array_diff_key($oldValues, array_flip($sensitiveFields));
        }
        if (is_array($newValues)) {
            $newValues = array_diff_key($newValues, array_flip($sensitiveFields));
        }

        // For updates, only log changed values
        if ($action === 'updated' && is_array($oldValues) && is_array($newValues)) {
            $changedKeys = array_keys($this->getChanges());
            $oldValues = array_intersect_key($oldValues, array_flip($changedKeys));
            $newValues = array_intersect_key($newValues, array_flip($changedKeys));
        }

        try {
            AuditLog::create([
                'user_id' => $userId,
                'auditable_type' => get_class($this),
                'auditable_id' => $this->getKey(),
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the main operation
            logger()->error('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Get audit log entries for this model.
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }
}
