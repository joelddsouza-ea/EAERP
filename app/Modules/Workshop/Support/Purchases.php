<?php

namespace App\Modules\Workshop\Support;

use App\Core\Support\WorkshopSettings;

/**
 * The optional purchases module (GRN, part verification, consumables stock, supplier
 * dues). Off: parts are marked received on the Parts Desk as before.
 */
class Purchases
{
    public static function enabled(): bool
    {
        return (bool) WorkshopSettings::get('purchases.enabled');
    }

    public static function minPartPhotos(): int
    {
        return max(0, (int) WorkshopSettings::get('purchases.min_part_photos'));
    }
}
