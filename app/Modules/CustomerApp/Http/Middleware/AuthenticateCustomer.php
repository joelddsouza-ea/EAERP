<?php

namespace App\Modules\CustomerApp\Http\Middleware;

use App\Modules\CustomerApp\Services\CustomerAuthService;
use App\Modules\CustomerApp\Support\AppViewer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer app requests: a customer session token (Bearer), never a staff login.
 * The customer is on the request as attribute "customer" (and the session as "customer_session").
 * "viewer" (AppViewer) also says whether a fleet's driver is signed in, and which cabs they see.
 */
class AuthenticateCustomer
{
    public function __construct(protected CustomerAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->auth->resolve($request->bearerToken());
        if (!$session) {
            return response()->json(['success' => false, 'message' => 'Please sign in again.'], 401);
        }

        $request->attributes->set('customer', $session->customer);
        $request->attributes->set('customer_session', $session);
        $request->attributes->set('viewer', new AppViewer($session->customer, $session->fleet_driver_id ? $session->driver : null));

        return $next($request);
    }
}
