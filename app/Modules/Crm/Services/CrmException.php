<?php

namespace App\Modules\Crm\Services;

use App\Modules\Workshop\Services\JobSheetException;

/**
 * A CRM rule is not met. Rendered like JobSheetException (422/403).
 */
class CrmException extends JobSheetException
{
}
