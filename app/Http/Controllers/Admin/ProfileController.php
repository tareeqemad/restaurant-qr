<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show()
    {
        return view('admin.profile.show', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // `current_password` is REQUIRED whenever the user wants to change
        // their password. Otherwise a stolen session could silently rotate
        // the password and lock the legitimate user out. Laravel's built-in
        // `current_password` rule validates against the auth user's hash.
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['nullable', 'email', "unique:users,email,{$user->id}"],
            'phone'            => ['nullable', 'string', 'max:20'],
            'current_password' => [
                'required_with:password',
                'nullable',
                'current_password',
            ],
            'password'         => ['nullable', 'min:6', 'confirmed'],
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
