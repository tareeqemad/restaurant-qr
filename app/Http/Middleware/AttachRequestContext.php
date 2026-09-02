<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every web request a short operator-friendly reference. Laravel's
 * normal exception reporter inherits the same logging context, so the code
 * shown on the 500 page can be searched directly in storage/logs.
 */
class AttachRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $reference = strtolower(substr((string) Str::ulid(), -12));
        $request->attributes->set('request_reference', $reference);

        Log::shareContext([
            'request_reference' => $reference,
            'request_method' => $request->method(),
            'request_path' => '/'.ltrim($request->path(), '/'),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'branch_id' => $request->session()->get('active_branch_id'),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Reference', $reference);

        return $response;
    }
}
