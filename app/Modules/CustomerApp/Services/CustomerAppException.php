<?php

namespace App\Modules\CustomerApp\Services;

use Exception;

/**
 * Something the customer (or staff confirming a UPI payment) has to fix; shown as a 422.
 */
class CustomerAppException extends Exception
{
    public function __construct(string $message, public array $errors = [], public int $status = 422)
    {
        parent::__construct($message);
    }
}
