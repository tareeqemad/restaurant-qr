<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    /**
     * Protect routes whose authority is represented by a named permission.
     * User::hasPermission() includes owner bypasses and per-user grants/revokes,
     * so the route and the permission-management screen share one source of
     * truth instead of relying on a broad role check.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless($request->user()?->hasPermission($permission), 403);

        return $next($request);
    }
}
