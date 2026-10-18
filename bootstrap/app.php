<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'branch.context' => \App\Core\Http\Middleware\BranchContext::class,
            'permission' => \App\Core\Http\Middleware\CheckPermission::class,
            'role' => \App\Core\Http\Middleware\CheckRole::class,
            'job.lifecycle' => \App\Modules\Workshop\Http\Middleware\SyncJobLifecycle::class,
            'purchases' => \App\Modules\Workshop\Http\Middleware\PurchasesEnabled::class,
            // module:accounts | module:crm | module:hr — 404 while that module is switched off
            'module' => \App\Core\Http\Middleware\ModuleEnabled::class,
            // Customer app: a customer session token, never a staff login
            'auth.customer' => \App\Modules\CustomerApp\Http\Middleware\AuthenticateCustomer::class,
        ]);

        // An HTTPS tunnel or reverse proxy on this machine (e.g. cloudflared while testing on phones)
        // tells us the visitor used https, so links and photo URLs are built as https too
        $middleware->trustProxies(at: explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')));

        // Using pure token-based API authentication
        // Add EnsureFrontendRequestsAreStateful back when building SPA with cookie-based auth
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Modules\Workshop\Services\JobSheetException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ], $e->status);
        });
    })->create();
