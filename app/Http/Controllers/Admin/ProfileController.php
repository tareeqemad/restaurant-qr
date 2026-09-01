<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminShell;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show()
    {
        $user = auth()->user()->loadMissing(['station', 'branches']);

        return AdminShell::render('Admin/Profile/Show', [
            'profile' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email ?? '',
                'phone' => $user->phone ?? '',
                'avatar' => $user->avatar_url,
                'role' => $user->role_label ?? $user->role,
                'station' => $user->station?->name,
                'memberSince' => $user->created_at->translatedFormat('j F Y'),
                'branches' => $user->branches->map(fn ($branch) => [
                    'id' => $branch->id,
                    'name' => $branch->localizedName(),
                    'primary' => (bool) $branch->pivot?->is_primary,
                ])->values(),
            ],
            'urls' => [
                'update' => route('admin.profile.update'),
                'dashboard' => route('admin.dashboard'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // `current_password` is REQUIRED whenever the user wants to change
        // their password. Otherwise a stolen session could silently rotate
        // the password and lock the legitimate user out. Laravel's built-in
        // `current_password` rule validates against the auth user's hash.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', "unique:users,email,{$user->id}"],
            'phone' => ['nullable', 'string', 'max:20'],
            'current_password' => [
                'required_with:password',
                'nullable',
                'current_password',
            ],
            'password' => ['nullable', 'min:6', 'confirmed'],
        ], [
            'current_password.required_with' => 'أدخل كلمة المرور الحالية لتأكيد التغيير.',
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        unset($data['password'], $data['current_password']);
        $user->update($data);

        return back()->with('success', 'تم تحديث الملف الشخصي بنجاح');
    }
}
