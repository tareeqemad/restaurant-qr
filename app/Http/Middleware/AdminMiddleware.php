<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->isSuspended()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => 'حسابك معطل. يرجى التواصل مع الإدارة.',
            ]);
        }

        if (! $user->canAccessAdmin()) {
            abort(403, 'غير مصرح لك بالدخول للوحة التحكم');
        }

        return $next($request);
    }
}
