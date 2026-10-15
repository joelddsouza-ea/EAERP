<?php

namespace App\Modules\Workshop\Services;

/**
 * An estimate or approval rule was violated. Rendered like JobSheetException (422/403/404).
 */
class EstimateException extends JobSheetException
{
}
