<?php

namespace App\Modules\Workshop\Providers;

use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardAdditionalJob;
use App\Modules\Workshop\Models\JobCardChecklistItem;
use App\Modules\Workshop\Models\JobCardIssue;
use App\Modules\Workshop\Models\JobTrip;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WorkshopServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short, stable type names for job-sheet media attachments
        Relation::morphMap([
            'sheet_item' => JobCardChecklistItem::class,
            'job_card_issue' => JobCardIssue::class,
            'job_card_additional_job' => JobCardAdditionalJob::class,
            'job_card' => JobCard::class, // intake inventory media
            'job_trip' => JobTrip::class, // drop / home-visit closing photos
        ]);

        // Load routes with api prefix and middleware
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');
    }
}
