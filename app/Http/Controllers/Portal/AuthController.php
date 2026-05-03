<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing auth flow — separate from staff /login.
 *
 *   GET  /portal/login     → form
 *   POST /portal/login     → attempt session login (phone OR email + password)
 *   GET  /portal/register  → form
 *   POST /portal/register  → create + auto-login
 *   POST /portal/logout    → end session
 */
class AuthController extends Controller
{
    public function showLogin(Request $request)
    {
        $this->storeSafeReturnTo($request);

        return view('portal.auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password'   => ['required', 'string'],
        ]);

        $customer = Customer::findForLogin($data['identifier']);
        if (! $customer || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        if ($customer->isBlocked()) {
            throw ValidationException::withMessages([
                'identifier' => 'حسابك معطَّل حالياً. تواصل مع المطعم.',
            ]);
        }

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $customer->update(['last_login_at' => now()]);

        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'));
    }

    public function showRegister(Request $request)
    {
        $this->storeSafeReturnTo($request);

        return view('portal.auth.register', [
            'branches' => Branch::where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'phone'             => ['required', 'string', 'max:32', 'unique:customers,phone'],
            'email'             => ['nullable', 'email', 'max:255', 'unique:customers,email'],
            'password'          => ['required', 'string', 'min:8', 'confirmed'],
            'default_branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
        ]);

        $customer = Customer::create([
            'name'              => $data['name'],
            'phone'             => $data['phone'],
            'email'             => $data['email'] ?? null,
            'password'          => $data['password'],   // hashed cast
            'default_branch_id' => $data['default_branch_id'] ?? null,
            'status'            => 'active',
        ]);

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->intended(route('portal.dashboard'))
            ->with('success', "أهلاً بك {$customer->name}!");
    }

    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    private function storeSafeReturnTo(Request $request): void
    {
        $returnTo = (string) $request->query('return_to', '');
        if ($returnTo === '') {
            return;
        }

        $appUrl = rtrim(url('/'), '/');
        $isLocalPath = str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//');
        $isSameAppUrl = str_starts_with($returnTo, $appUrl.'/') || $returnTo === $appUrl;

        if ($isLocalPath || $isSameAppUrl) {
            $request->session()->put('url.intended', $returnTo);
        }
    }
}
