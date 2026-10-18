<?php

namespace App\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use App\Core\Services\BranchContextService;
use App\Core\Services\Auth\RbacService;
use App\Core\Services\NotificationService;
use App\Core\Services\FileStorageService;
use App\Core\Services\ExportService;
use App\Core\Services\WhatsApp\InteraktChannel;
use App\Core\Services\WhatsApp\ShareLinkChannel;
use App\Core\Services\WhatsApp\WhatsAppChannel;
use App\Core\Services\WhatsApp\WhatsAppSettings;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register BranchContext as singleton
        $this->app->singleton('branch.context', function ($app) {
            return new BranchContextService();
        });

        $this->app->singleton(BranchContextService::class, function ($app) {
            return $app->make('branch.context');
        });

        // Register RBAC Service as singleton
        $this->app->singleton(RbacService::class, function ($app) {
            return new RbacService();
        });

        // Register Notification Service
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });

        // Register File Storage Service
        $this->app->singleton(FileStorageService::class, function ($app) {
            return new FileStorageService();
        });

        // WhatsApp: share link (workshop phone) unless Interakt is switched on in Settings › WhatsApp
        // (not a singleton: the owner may switch it while the app runs)
        $this->app->bind(WhatsAppChannel::class, function ($app) {
            return WhatsAppSettings::apiOn() ? new InteraktChannel() : new ShareLinkChannel();
        });

        // Register Export Service
        $this->app->singleton(ExportService::class, function ($app) {
            return new ExportService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load core routes with api prefix and middleware
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([\App\Core\Console\SendDueWhatsApp::class]);
        }

        // Load core views
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'core');

        // Publish core config
        $this->publishes([
            __DIR__ . '/../Config/core.php' => config_path('core.php'),
        ], 'core-config');
    }
}
