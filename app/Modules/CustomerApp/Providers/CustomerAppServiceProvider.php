<?php

namespace App\Modules\CustomerApp\Providers;

use App\Core\Support\Modules;
use App\Modules\CustomerApp\Console\CustomerAppReminders;
use App\Modules\CustomerApp\Services\Ai\AiSettings;
use App\Modules\CustomerApp\Services\Ai\AnthropicClaudeClient;
use App\Modules\CustomerApp\Services\Ai\ClaudeClient;
use App\Modules\CustomerApp\Services\CustomerNotifier;
use App\Modules\Workshop\Enums\JobCardStatus;
use App\Modules\Workshop\Models\JobCard;
use App\Modules\Workshop\Models\JobCardStatusHistory;
use App\Modules\Workshop\Models\WorkflowStage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CustomerAppServiceProvider extends ServiceProvider
{
    /** Stages the customer always hears about in the app, besides those ticked in the Job stages master */
    public const ALWAYS = ['pickup_en_route', 'home_visit_en_route', 'vehicle_collected', 'awaiting_approval', 'ready_for_delivery', 'delivered'];

    public function register(): void
    {
        $this->app->bind(ClaudeClient::class, fn () => new AnthropicClaudeClient((string) AiSettings::apiKey()));
    }

    public function boot(): void
    {
        // Customers aren't staff logins: limits are kept per customer (signed in) or per phone / IP
        $customerKey = fn (Request $r) => 'c' . ($r->attributes->get('customer')?->id ?? $r->ip());
        RateLimiter::for('customer', fn (Request $r) => Limit::perMinute(120)->by($customerKey($r)));
        RateLimiter::for('customer-heavy', fn (Request $r) => Limit::perMinute(10)->by($customerKey($r) . '|' . $r->route()?->uri()));
        RateLimiter::for('customer-sign-in', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('customer-code', fn (Request $r) => [
            Limit::perMinute(6)->by($r->ip()),
            Limit::perHour(5)->by('phone|' . preg_replace('/\D/', '', (string) $r->input('phone'))),
        ]);

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CustomerAppReminders::class]);
        }

        // A job moving stage lands in the customer's app inbox (and on their phone)
        JobCardStatusHistory::created(function (JobCardStatusHistory $h) {
            $jobCardId = (int) $h->job_card_id;
            $status = (string) $h->to_status;
            DB::afterCommit(fn () => $this->stageChanged($jobCardId, $status));
        });
    }

    protected function stageChanged(int $jobCardId, string $status): void
    {
        if (!Modules::customerApp()) {
            return;
        }
        $stage = WorkflowStage::for($status);
        if (!in_array($status, self::ALWAYS, true) && !$stage?->notify_customer) {
            return;
        }
        $job = JobCard::withoutGlobalScopes()->with('vehicle:id,registration_no')->find($jobCardId);
        if (!$job?->customer_id) {
            return;
        }

        $label = $stage?->label ?: (JobCardStatus::tryFrom($status)?->label() ?? $status);
        $body = match ($status) {
            'awaiting_approval' => 'Your estimate is ready. Approve or decline each item in the app.',
            'ready_for_delivery' => 'Your car is ready. See the bill and pay by UPI in the app.',
            'pickup_en_route', 'home_visit_en_route' => 'Our team is on the way. Track them live in the app.',
            'delivered' => 'Thank you! Your service report and bill are in the app.',
            default => null,
        };
        $kind = $status === 'awaiting_approval' ? 'approval' : (str_contains($status, 'en_route') ? 'driver' : 'stage');

        app(CustomerNotifier::class)->notify(
            $job->customer_id,
            $kind,
            ($job->vehicle?->registration_no ? $job->vehicle->registration_no . ': ' : '') . $label,
            $body,
            "/app/jobs/{$job->id}",
            "stage:{$job->id}:{$status}",
        );
    }
}
