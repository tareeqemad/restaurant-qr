<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenseManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            // A blocked local state could be a genuine block or a remote change
            // we haven't pulled yet. Re-verify against the cloud — but at most
            // once per short window (cache lock). Without this guard a slow or
            // unreachable license server turns EVERY write request into a
            // multi-second HTTP stall, since this branch fires on each POST while
            // the state stays non-allowing. Losers of the lock decide from the
            // local state and continue instantly.
            if (Cache::add('license:recheck-lock', 1, now()->addMinutes(2))) {
                $manager->refresh();
            }
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
