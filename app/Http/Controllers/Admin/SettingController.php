<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin', 'manager']), 403);
        return view('admin.settings.index', [
            'defaults'   => config('restaurant.theme'),
            'currencies' => \App\Models\Currency::orderBy('display_order')->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $data = $request->validate([
            'site_name' => ['required', 'string'],
            'tax_rate' => ['required', 'numeric', 'min:0'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'service_rate' => ['required', 'numeric', 'min:0'],
            'service_enabled' => ['sometimes', 'boolean'],
            'currency_symbol' => ['required', 'string'],
            'auto_approve' => ['sometimes', 'boolean'],
            // Theme color settings
            'theme_primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_dark' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_menu' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header_style' => ['nullable', 'in:light,dark,color'],
            'theme_menu_style' => ['nullable', 'in:light,dark,brand'],
        ]);

        foreach ($data as $k => $v) {
            if ($v === null || $v === '') continue;
            Setting::put($k, $v);
        }

        return back()->with('success', 'تم الحفظ');
    }

    public function resetTheme(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $keys = ['theme_primary', 'theme_dark', 'theme_header', 'theme_accent', 'theme_menu', 'theme_header_style', 'theme_menu_style'];
        foreach ($keys as $key) {
            Setting::where('key', $key)->delete();
            \Cache::forget('setting.'.$key);
        }

        return back()->with('success', 'تم استعادة ألوان الهوية الافتراضية');
    }
}
