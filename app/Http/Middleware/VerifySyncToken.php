<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the cloud-side /api/sync/* endpoints. Branches present the shared
 * secret as a bearer token; anything missing or mismatched is rejected before
 * it reaches a controller. Uses hash_equals to avoid timing leaks.
 */
class VerifySyncToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('sync.accept_token');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid sync token.');
        }

        return $next($request);
    }
}
