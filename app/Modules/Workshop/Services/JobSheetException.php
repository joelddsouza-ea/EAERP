<?php

namespace App\Modules\Workshop\Services;

use RuntimeException;

/**
 * A job-sheet rule was violated (e.g. Done without required media). Rendered as 422/403.
 */
class JobSheetException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
