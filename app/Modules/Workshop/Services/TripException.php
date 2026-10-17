<?php

namespace App\Modules\Workshop\Services;

/**
 * A pickup / drop / home-visit step is not allowed. Rendered like JobSheetException (422/403).
 */
class TripException extends JobSheetException
{
}
