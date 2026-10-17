<?php

namespace App\Modules\Accounts\Services;

use App\Modules\Workshop\Services\JobSheetException;

/**
 * An accounting rule is not met (unbalanced entry, locked month…). Rendered like JobSheetException.
 */
class LedgerException extends JobSheetException
{
}
