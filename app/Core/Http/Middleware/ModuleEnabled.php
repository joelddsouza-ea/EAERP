<?php

namespace App\Core\Http\Middleware;

use App\Core\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes of an optional module answer 404 while it is switched off (module:crm …).
 */
class ModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (!Modules::enabled($module)) {
            return response()->json([
                'success' => false,
                'message' => 'The ' . (Modules::SWITCHABLE[$module] ?? $module) . ' module is switched off (Settings › Modules).',
            ], 404);
        }

        return $next($request);
    }
}
