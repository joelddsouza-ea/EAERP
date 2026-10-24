<?php

namespace App\Modules\Fleet\Providers;

use App\Core\Support\Modules;
use App\Modules\Fleet\Console\FleetDaily;
use App\Modules\Fleet\Models\FleetDriver;
use App\Modules\Fleet\Services\FleetDriverService;
use App\Modules\Fleet\Services\FleetNotifications;
use App\Modules\Fleet\Services\FleetReportService;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Workshop\Models\Customer;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 10: fleets (cab owners) and their drivers.
 */
class FleetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([FleetDaily::class]);
        }

        // A new job card: its km reading is logged, and the cab's open problem reports move to it
        JobCard::created(function (JobCard $job) {
            $id = (int) $job->id;
            DB::afterCommit(fn () => $this->quietly(function () use ($id) {
                $job = JobCard::withoutGlobalScopes()->find($id);
                if (!$job) {
                    return;
                }
                app(OdometerService::class)->fromJobCard($job);
                if (Modules::fleet()) {
                    app(FleetReportService::class)->jobCardOpened($job);
                }
            }));
        });

        // No longer a fleet (type changed at the customer form or "Cab fleet" unticked): its drivers can't sign in
        Customer::updated(function (Customer $customer) {
            if ($customer->wasChanged('is_fleet') && !$customer->is_fleet) {
                $drivers = app(FleetDriverService::class);
                FleetDriver::where('customer_id', $customer->id)->get()->each(fn (FleetDriver $d) => $drivers->signOut($d));
            }
        });

        // The cab is ready: the driver who brought it in gets a WhatsApp message (share link)
        JobCardStatusHistory::created(function (JobCardStatusHistory $h) {
            if ($h->to_status !== 'ready_for_delivery') {
                return;
            }
            $id = (int) $h->job_card_id;
            DB::afterCommit(fn () => $this->quietly(function () use ($id) {
                $job = JobCard::withoutGlobalScopes()->find($id);
                if ($job) {
                    FleetNotifications::cabReady($job);
                }
            }));
        });
    }

    protected function quietly(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            report($e);
        }
    }
}
