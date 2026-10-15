<?php

namespace App\Core\Support;

use App\Core\Models\Organization;

/**
 * Owner-editable workshop settings: organization settings override the
 * defaults in config/workshop.php (same key, e.g. "intake.min_photos").
 */
class WorkshopSettings
{
    public static function get(string $key): mixed
    {
        return Organization::instance()?->getSetting($key) ?? config("workshop.{$key}");
    }

    public static function set(string $key, mixed $value): void
    {
        Organization::instance()?->setSetting($key, $value);
    }
}
