<?php

namespace App\Modules\Workshop\Models;

use App\Core\Models\BaseModel;

class Customer extends BaseModel
{
    protected $fillable = [
        'name',
        'phone',
        'alt_phone',
        'whatsapp_phone',
        'email',
        'address',
        'city',
        'state',
        'pincode',
        'gstin',
        'customer_type',
        'company_name',
        'notes',
        'is_active',
        // walk_in | referral | online | lead | corporate | other; lead_id for the CRM phase
        'source',
        'lead_id',
    ];

    public const SOURCES = ['walk_in' => 'Walk-in', 'referral' => 'Referral', 'online' => 'Online / Google', 'lead' => 'Lead (marketing)', 'corporate' => 'Corporate', 'other' => 'Other'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Vehicles owned by this customer.
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Job cards for this customer.
     */
    public function jobCards()
    {
        return $this->hasMany(JobCard::class);
    }

    /**
     * Follow-up calls for this customer.
     */
    /**
     * Saved pickup / drop / home-service addresses.
     */
    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderByDesc('id');
    }

    public function followUpCalls()
    {
        return $this->hasMany(FollowUpCall::class);
    }

    /**
     * Get display name (name + company for corporate).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->customer_type === 'corporate' && $this->company_name) {
            return "{$this->name} ({$this->company_name})";
        }
        return $this->name;
    }

    /**
     * Scope to search customers across multiple fields.
     */
    public function scopeQuickSearch($query, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('company_name', 'like', "%{$term}%");
        });
    }
}
