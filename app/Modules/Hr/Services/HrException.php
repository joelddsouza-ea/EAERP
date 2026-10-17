<?php

namespace App\Modules\Hr\Services;

use App\Modules\Workshop\Services\JobSheetException;

/**
 * An attendance, leave or payroll rule is not met. Rendered like JobSheetException (422/403).
 */
class HrException extends JobSheetException
{
}
