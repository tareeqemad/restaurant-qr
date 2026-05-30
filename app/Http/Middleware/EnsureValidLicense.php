<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenseManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidLicense
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('license.enabled') || $this->isExempt($request) || $request->isMethodSafe()) {
            return $next($request);
        }

        $manager = app(LicenseManager::class);
        $manager->refreshIfStale();

        if (! $manager->allowsOperation()) {
            $manager->refresh();
        }

        $summary = $manager->summary();
        if ($summary['allows_operations']) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $summary['message'],
                'license_status' => $summary['status'],
            ], 423);
        }

        return redirect()
            ->back()
            ->withErrors(['license' => $summary['message']])
            ->with('warning', $summary['message']);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs(
            'admin.license-status.*',
            'admin.licenses.*',
            'admin.sync.*'
        );
    }
}
