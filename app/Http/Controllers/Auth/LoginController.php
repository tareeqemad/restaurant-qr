<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function show(Request $request)
    {
        if ($user = Auth::user()) {
            if ($user->canLogin() && $user->canAccessAdmin()) {
                return redirect()->route('admin.dashboard');
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return view('auth.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $credentials = str_contains($data['username'], '@')
            ? ['email' => $data['username'], 'password' => $data['password']]
            : ['username' => $data['username'], 'password' => $data['password']];

        if (! Auth::attempt($credentials, (bool) ($data['remember'] ?? false))) {
            return back()->withErrors(['username' => 'بيانات الدخول غير صحيحة'])->withInput($request->only('username'));
        }

        $user = Auth::user();

        if (! $user->canLogin()) {
            Auth::logout();
            return back()->withErrors(['username' => 'حسابك معطل. يرجى التواصل مع الإدارة.']);
        }

        $user->update(['last_login_at' => now()]);
        $request->session()->regenerate();
        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
