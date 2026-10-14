<?php

namespace App\Modules\Workshop\Workflow\Guards;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Workflow\Guard;
use App\Modules\Workshop\Workflow\GuardFailure;

/**
 * Intake must record the odometer and fuel level before work starts.
 */
class IntakeCaptured implements Guard
{
    public function check(JobCard $jobCard): ?GuardFailure
    {
        $missing = array_keys(array_filter([
            'km_reading' => $jobCard->km_reading === null,
            'fuel_level' => $jobCard->fuel_level === null,
        ]));

        return $missing
            ? new GuardFailure('intake_incomplete', 'Record the odometer reading and fuel level at intake.', ['fields' => $missing])
            : null;
    }
}
