<?php

namespace App\Http\Middleware;

use App\Support\FirstRunSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirstRunSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (FirstRunSetup::shouldRunWizard()) {
            return redirect()->route('setup.show');
        }

        return $next($request);
    }
}
