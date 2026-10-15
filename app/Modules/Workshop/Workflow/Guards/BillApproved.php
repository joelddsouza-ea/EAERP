<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Billing\Services\BillingService;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * The final bill (proforma or tax invoice) must be approved by back office.
 */
class BillApproved implements Guard
{
    public function __construct(private BillingService $billing) {}

    public function check(JobCard $jobCard): ?GuardFailure
    {
        return $this->billing->payableBill($jobCard)
            ? null
            : new GuardFailure('bill_not_approved', 'Back office has not approved the final bill (proforma) yet.');
    }
}
