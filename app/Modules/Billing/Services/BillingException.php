<?php

namespace App\Modules\Billing\Services;

use App\Modules\Workshop\Services\JobSheetException;

/**
 * A billing rule was violated. Rendered like JobSheetException (422/403/404).
 */
class BillingException extends JobSheetException
{
}
