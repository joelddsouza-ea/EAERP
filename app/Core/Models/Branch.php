<?php

namespace App\Core\Models;

class Branch extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'gstin',
        'is_active',
        // Attendance: the workshop's location, the punch radius, the weekly off (0 = Sunday)
        'lat',
        'lng',
        'geofence_radius_m',
        'weekly_off_day',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'lat' => 'float',
        'lng' => 'float',
        'geofence_radius_m' => 'integer',
        'weekly_off_day' => 'integer',
    ];

    /**
     * Users relationship - users assigned to this branch.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_branches')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get the organization this branch belongs to.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope to active branches.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get branch display name with code.
     */
    public function getDisplayNameAttribute(): string
    {
        return "{$this->name} ({$this->code})";
    }
}
