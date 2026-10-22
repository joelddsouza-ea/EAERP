<?php

namespace App\Modules\Fleet\Services;

use Exception;

/**
 * Something the fleet owner, driver or staff has to fix; shown as a 422.
 */
class FleetException extends Exception
{
    public function __construct(string $message, public array $errors = [], public int $status = 422)
    {
        parent::__construct($message);
    }
}
