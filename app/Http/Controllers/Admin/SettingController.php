<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'site_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'customer_tax_display' => ['required', 'in:exclusive,inclusive'],
            'service_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'service_enabled' => ['sometimes', 'boolean'],
            'currency_symbol' => ['required', 'string', 'max:10'],
            'customer_currency_switcher' => ['sometimes', 'boolean'],
            'customer_cancel_window_seconds' => ['required', 'integer', 'min:0', 'max:900'],
            'session_ttl_minutes' => ['required', 'integer', 'min:30', 'max:1440'],
            'strict_stock' => ['sometimes', 'boolean'],
            'inventory_deduction_stage' => ['sometimes', 'in:approve,preparing,ready,served'],
            // Staff meals: branch-level toggle for whether the service
            // charge stays on the employee's tab. Default off.
            'staff_meal_include_service' => ['sometimes', 'boolean'],
            // Theme color settings
            'theme_primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_dark' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_menu' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header_style' => ['nullable', 'in:light,dark,color'],
            'theme_menu_style' => ['nullable', 'in:light,dark,brand'],
            // Discount caps per role — admins can tune the per-role
            // ceiling (max % and max fixed amount) without redeploying.
            'discount_cap_cashier_pct'   => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'discount_cap_cashier_fixed' => ['sometimes', 'numeric', 'min:0'],
            'discount_cap_waiter_pct'    => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'discount_cap_waiter_fixed'  => ['sometimes', 'numeric', 'min:0'],
            'discount_cap_manager_pct'   => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'discount_cap_manager_fixed' => ['sometimes', 'numeric', 'min:0'],
            // Prep-time buffer: extra % piled on top of max(item prep_time)
            // when computing the customer-facing ETA + the kitchen's
            // "should be ready by" stamp. 20% is the rule-of-thumb default;
            // capped at 200 so a typo can't quote "ready in 4 hours".
            'prep_time_buffer_pct' => ['sometimes', 'integer', 'min:0', 'max:200'],
        ]);

        $meta = [
            'site_name' => ['general', 'string'],
            'legal_name' => ['general', 'string'],
            'tax_number' => ['general', 'string'],
            'receipt_footer' => ['billing', 'string'],
            'currency_symbol' => ['billing', 'string'],
            'tax_rate' => ['billing', 'float'],
            'tax_enabled' => ['billing', 'bool'],
            'customer_tax_display' => ['billing', 'string'],
            'service_rate' => ['billing', 'float'],
            'service_enabled' => ['billing', 'bool'],
            'customer_currency_switcher' => ['customer', 'bool'],
            'customer_cancel_window_seconds' => ['customer', 'int'],
            'session_ttl_minutes' => ['customer', 'int'],
            'strict_stock' => ['inventory', 'bool'],
            'inventory_deduction_stage' => ['inventory', 'string'],
            'staff_meal_include_service' => ['staff_meals', 'bool'],
            'theme_primary' => ['theme', 'string'],
            'theme_dark' => ['theme', 'string'],
            'theme_header' => ['theme', 'string'],
            'theme_accent' => ['theme', 'string'],
            'theme_menu' => ['theme', 'string'],
            'theme_header_style' => ['theme', 'string'],
            'theme_menu_style' => ['theme', 'string'],
            'discount_cap_cashier_pct'   => ['discounts', 'float'],
            'discount_cap_cashier_fixed' => ['discounts', 'float'],
            'discount_cap_waiter_pct'    => ['discounts', 'float'],
            'discount_cap_waiter_fixed'  => ['discounts', 'float'],
            'discount_cap_manager_pct'   => ['discounts', 'float'],
            'discount_cap_manager_fixed' => ['discounts', 'float'],
            'prep_time_buffer_pct'       => ['general', 'int'],
        ];

        $clearable = ['legal_name', 'tax_number', 'receipt_footer'];

        foreach ($data as $k => $v) {
            if ($v === null || $v === '') {
                if (in_array($k, $clearable, true)) {
                    Setting::where('key', $k)->delete();
                    \Cache::forget('setting.'.$k);
                }
                continue;
            }
            [$group, $type] = $meta[$k] ?? ['general', 'string'];
            Setting::put($k, $v, $group, $type);
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

    /**
     * Upload brand assets: main logo (shown on admin header, login, customer
     * topbar, invoices) and/or favicon (browser tab). Either can be
     * uploaded independently. Old files are deleted to keep storage clean.
     */
    public function updateBrand(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        $request->validate([
            'brand_logo'    => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'brand_favicon' => ['nullable', 'image', 'mimes:png,ico,jpg,webp,svg', 'max:512'],
        ], [], [
            'brand_logo'    => 'شعار البرنامج',
            'brand_favicon' => 'أيقونة التبويب',
        ]);

        foreach (['brand_logo', 'brand_favicon'] as $field) {
            if (! $request->hasFile($field)) continue;

            // Delete old uploaded file (if any) so storage doesn't accumulate.
            $previous = Setting::get($field);
            if ($previous) {
                Storage::disk('public')->delete($previous);
            }

            $path = $request->file($field)->store('brand', 'public');
            Storage::disk('public')->setVisibility($path, 'public');
            $this->makePubliclyReadable(Storage::disk('public')->path($path));
            Setting::put($field, $path, 'brand');
            clearstatcache();
        }

        return back()->with('success', 'تم تحديث شعارات البرنامج');
    }

    /** Delete a single uploaded brand asset and revert to default. */
    public function deleteBrand(Request $request, string $key)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);
        abort_unless(in_array($key, ['brand_logo', 'brand_favicon']), 404);

        $path = Setting::get($key);
        if ($path) {
            Storage::disk('public')->delete($path);
        }
        Setting::where('key', $key)->delete();
        \Cache::forget('setting.'.$key);
        clearstatcache();

        return back()->with('success', 'تم حذف الشعار وإرجاع الافتراضي');
    }

    private function makePubliclyReadable(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        @chmod($path, 0644);

        $directory = dirname($path);
        while ($directory && $directory !== dirname($directory)) {
            @chmod($directory, 0755);

            if ($directory === storage_path('app/public')) {
                break;
            }

            $directory = dirname($directory);
        }
    }
}
