<?php

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Console\CrmDaily;
use App\Modules\Crm\Console\RunCrm;
use App\Modules\Crm\Services\RetentionService;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RunCrm::class, CrmDaily::class]);
        }

        // A delivered job wins its lead, starts the thank-you / feedback journey, pending
        // work and referral rewards — once the change is committed
        JobCardStatusHistory::created(function (JobCardStatusHistory $h) {
            $jobCardId = (int) $h->job_card_id;
            $status = (string) $h->to_status;
            DB::afterCommit(function () use ($jobCardId, $status) {
                if (!\App\Core\Support\Modules::crm()) {
                    return;
                }
                try {
                    app(RetentionService::class)->onJobStatus($jobCardId, $status);
                } catch (\Throwable $e) {
                    if (app()->runningUnitTests()) {
                        throw $e;
                    }
                    report($e);
                }
            });
        });
    }
}
