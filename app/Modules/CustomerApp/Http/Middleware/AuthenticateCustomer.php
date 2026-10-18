<?php

namespace App\Modules\CustomerApp\Http\Middleware;

use App\Modules\CustomerApp\Services\CustomerAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer app requests: a customer session token (Bearer), never a staff login.
 * The customer is on the request as attribute "customer" (and the session as "customer_session").
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

        return $next($request);
    }
}
