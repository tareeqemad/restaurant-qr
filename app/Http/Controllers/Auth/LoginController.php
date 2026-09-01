<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\Brand;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FirstRunSetup;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function show(Request $request)
    {
        if (FirstRunSetup::shouldRunWizard() && ! FirstRunSetup::hasUsers()) {
            return redirect()->route('setup.show');
        }

        if ($user = Auth::user()) {
            if ($user->canLogin() && $user->canAccessAdmin()) {
                return redirect()->route('admin.dashboard');
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        Inertia::setRootView('inertia');

        return Inertia::render('Auth/Login', [
            'brand' => [
                'name' => Brand::name(),
                'logo' => Brand::logoUrl(),
            ],
            'routes' => [
                'login' => route('login.store'),
                'forgotPassword' => route('password.request'),
            ],
            'oldUsername' => (string) $request->old('username', ''),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'username' => trim((string) $request->input('username')),
        ]);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($data['remember'] ?? false);
        $credentials = str_contains($data['username'], '@')
            ? ['email' => $data['username'], 'password' => $data['password']]
            : ['username' => $data['username'], 'password' => $data['password']];

        $authenticated = Auth::attempt($credentials, $remember);

        // The screen has always promised "mobile or username". Honour that
        // contract while accepting Arabic digits and +970/00970 variants.
        if (! $authenticated && ! str_contains($data['username'], '@') && PhoneNumber::isValid($data['username'])) {
            $phoneMatches = User::query()
                ->whereIn('phone', PhoneNumber::lookupVariants($data['username']))
                ->limit(2)
                ->get();

            // A duplicated staff phone is ambiguous and must never choose an
            // account silently; an administrator can correct it first.
            if ($phoneMatches->count() === 1) {
                $authenticated = Auth::attempt([
                    'id' => $phoneMatches->first()->id,
                    'password' => $data['password'],
                ], $remember);
            }
        }

        if (! $authenticated) {
            return back()->withErrors(['username' => __('ui.auth.invalid_credentials')])->withInput($request->only('username'));
        }

        $user = Auth::user();

        if (! $user->canLogin()) {
            Auth::logout();

            return back()->withErrors(['username' => __('ui.auth.account_disabled')]);
        }

        $user->update(['last_login_at' => now()]);
        $request->session()->regenerate();

        if (FirstRunSetup::shouldRunWizard()) {
            return redirect()->route('setup.show');
        }

        $destination = (string) $request->session()->pull('url.intended', route('admin.dashboard'));

        // Login and admin pages intentionally use different Inertia root
        // documents. A normal SPA redirect would keep the lean login shell
        // mounted around AdminLayout, leaving Dashtic's structural CSS out
        // until the employee refreshes the page. Force one document visit at
        // this boundary; navigation inside the admin area remains fully SPA.
        if ($request->header('X-Inertia')) {
            return Inertia::location($destination);
        }

        return redirect()->to($destination);
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
