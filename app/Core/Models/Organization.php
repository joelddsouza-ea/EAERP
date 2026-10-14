<?php

namespace App\Core\Models;

class Organization extends BaseModel
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'legal_name',
        'logo',
        'address',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'website',
        'gstin',
        'pan',
        'settings',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Branches relationship.
     */
    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Get a setting value.
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set a setting value.
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        $this->save();
    }

    /**
     * Get the singleton organization instance.
     */
    public static function instance(): ?self
    {
        return static::first();
    }
}
