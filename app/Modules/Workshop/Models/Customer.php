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
        'referral_code',
        'marketing_opt_out_at',
        'birthday',
        // Phase 10: cab fleets (drivers sign in to the app; limit null = Settings › Fleet default)
        'is_fleet',
        'fleet_driver_limit',
    ];

    public const SOURCES = ['walk_in' => 'Walk-in', 'referral' => 'Referral', 'online' => 'Online / Google', 'lead' => 'Lead (marketing)', 'corporate' => 'Corporate', 'other' => 'Other'];

    protected $casts = [
        'is_active' => 'boolean',
        'marketing_opt_out_at' => 'datetime',
        'birthday' => 'date',
        'is_fleet' => 'boolean',
        'fleet_driver_limit' => 'decimal:2',
    ];

    public const TYPES = ['individual' => 'Individual', 'corporate' => 'Corporate', 'fleet' => 'Fleet'];

    /**
     * Customer type 'fleet' and is_fleet move together: picking Fleet at intake makes a fleet,
     * and ticking / unticking "Cab fleet" on the customer page changes the type.
     */
    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if (!$customer->exists) {
                if ($customer->is_fleet || $customer->customer_type === 'fleet') {
                    $customer->customer_type = 'fleet';
                    $customer->is_fleet = true;
                }
            } elseif ($customer->isDirty('customer_type')) {
                $customer->is_fleet = $customer->customer_type === 'fleet';
            } elseif ($customer->isDirty('is_fleet')) {
                if ($customer->is_fleet) {
                    $customer->customer_type = 'fleet';
                } elseif ($customer->customer_type === 'fleet') {
                    $customer->customer_type = $customer->company_name ? 'corporate' : 'individual';
                }
            }
        });
    }

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

    /**
     * A fleet's drivers.
     */
    public function drivers()
    {
        return $this->hasMany(\App\Modules\Fleet\Models\FleetDriver::class);
    }

    public function followUpCalls()
    {
        return $this->hasMany(FollowUpCall::class);
    }

    /**
     * Get display name (name + company for corporate and fleet).
     */
    public function getDisplayNameAttribute(): string
    {
        if (in_array($this->customer_type, ['corporate', 'fleet'], true) && $this->company_name) {
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
