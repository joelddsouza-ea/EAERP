<?php

namespace App\Modules\Fleet\Services;

use App\Core\Support\Modules;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Services\CustomerMessageService;

/**
 * Who among a fleet's drivers hears about a job: the driver who brought the cab in, else
 * every active driver of that cab. Nobody when the customer is not a fleet.
 */
class FleetNotifications
{
    /**
     * @return array<int>
     */
    public static function driversFor(JobCard $job): array
    {
        if (!Modules::fleet() || !$job->customer_id || !Customer::whereKey($job->customer_id)->value('is_fleet')) {
            return [];
        }
        if ($job->fleet_driver_id) {
            return FleetDriver::whereKey($job->fleet_driver_id)->where('is_active', true)->pluck('id')->all();
        }

        return FleetDriver::where('customer_id', $job->customer_id)->where('is_active', true)
            ->whereHas('vehicles', fn ($q) => $q->where('vehicles.id', $job->vehicle_id))
            ->pluck('id')->all();
    }

    /**
     * The cab is ready: a WhatsApp message (share link) to the driver who brought it in, with
     * their own link to the bill. Once per job.
     */
    public static function cabReady(JobCard $job): void
    {
        if (!Modules::fleet() || !$job->fleet_driver_id) {
            return;
        }
        $driver = FleetDriver::where('is_active', true)->find($job->fleet_driver_id);
        if (!$driver || $job->customerMessages()->where('template', 'fleet_driver_ready')->exists()) {
            return;
        }
        app(CustomerMessageService::class)->prepareForDriver($job, $driver, 'fleet_driver_ready');
    }
}
