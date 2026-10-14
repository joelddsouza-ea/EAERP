<?php

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Cache key prefix.
     */
    protected const CACHE_PREFIX = 'settings.';

    /**
     * Get a setting value.
     */
    public static function get(string $key, $default = null)
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            now()->addHours(24),
            function () use ($key, $default) {
                $setting = static::where('key', $key)->first();

                if (!$setting) {
                    return $default;
                }

                return self::castValue($setting->value, $setting->type);
            }
        );
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, $value, ?string $type = null, ?string $group = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? $value : ['value' => $value],
                'type' => $type ?? self::detectType($value),
                'group' => $group,
            ]
        );

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Delete a setting.
     */
    public static function remove(string $key): void
    {
        static::where('key', $key)->delete();
        Cache::forget(self::CACHE_PREFIX . $key);
    }

    /**
     * Clear all settings cache.
     */
    public static function clearCache(): void
    {
        $keys = static::pluck('key');

        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }

    /**
     * Get all settings for a group.
     */
    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->mapWithKeys(fn($s) => [$s->key => self::castValue($s->value, $s->type)])
            ->toArray();
    }

    /**
     * Cast value based on type.
     */
    protected static function castValue($value, ?string $type)
    {
        if (is_array($value) && isset($value['value'])) {
            $value = $value['value'];
        }

        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'float' => (float) $value,
            'array', 'json' => is_array($value) ? $value : json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Detect value type.
     */
    protected static function detectType($value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }
}
