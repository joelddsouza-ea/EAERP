<?php

namespace App\Modules\Workshop\Http\Middleware;

use App\Modules\Workshop\Support\Purchases;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes of the purchases module answer 404 while the module is switched off.
 */
class PurchasesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Purchases::enabled()) {
            return response()->json([
                'success' => false,
                'message' => 'The purchases & stock module is switched off (Masters › Purchases & Stock).',
            ], 404);
        }

        return $next($request);
    }
}
