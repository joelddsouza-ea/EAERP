<?php

namespace App\Modules\Hr\Providers;

use App\Modules\Hr\Console\CloseAttendance;
use App\Modules\Hr\Console\TrackingHousekeeping;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CloseAttendance::class, TrackingHousekeeping::class]);
        }
    }
}
