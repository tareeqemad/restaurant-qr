<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Helpers\Brand;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencyExchangeRate;
use App\Models\Role;
use App\Models\Setting;
use App\Services\SmsService;
use App\Support\AdminShell;
use App\Support\MarketProfile;
use App\Support\PaymentMethods;
use App\Support\ThemePalette;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function index()
    {
        $actor = auth()->user();
        abort_unless($actor->hasAnyRole(['super_admin', 'admin', 'manager']), 403);

        $canEdit = $actor->hasAnyRole(['super_admin', 'admin']);
        $currencies = Currency::orderBy('display_order')->orderBy('code')->get();
        $themeDefaults = config('restaurant.theme', []);
        $theme = ThemePalette::current();
        $caps = config('restaurant.discounts.caps', []);

        $values = [
            'site_name' => Setting::get('site_name', config('restaurant.name')),
            'legal_name' => Setting::get('legal_name', ''),
            'tax_number' => Setting::get('tax_number', ''),
            'currency_symbol' => Setting::get('currency_symbol', config('restaurant.currency_symbol')),
            'customer_tax_display' => Setting::get('customer_tax_display', 'exclusive'),
            'service_rate' => (float) Setting::get('service_rate', config('restaurant.service_charge.rate')),
            'service_enabled' => (bool) Setting::get('service_enabled', config('restaurant.service_charge.enabled')),
            'receipt_footer' => Setting::get('receipt_footer', 'شكراً لزيارتكم'),
            'bank_transfer_details' => Setting::get('bank_transfer_details', ''),
            'bank_name' => Setting::get('bank_name', ''),
            'bank_account_holder' => Setting::get('bank_account_holder', ''),
            'bank_account_number' => Setting::get('bank_account_number', ''),
            'bank_iban' => Setting::get('bank_iban', ''),
            'palpay_wallet_number' => Setting::get('palpay_wallet_number', ''),
            'jawwal_pay_wallet_number' => Setting::get('jawwal_pay_wallet_number', ''),
            'customer_currency_switcher' => (bool) Setting::get('customer_currency_switcher', config('restaurant.customer.currency_switcher', false)),
            'customer_cancel_window_seconds' => (int) Setting::get('customer_cancel_window_seconds', config('restaurant.order.customer_cancel_window_seconds', 120)),
            'session_ttl_minutes' => (int) Setting::get('session_ttl_minutes', config('restaurant.order.session_ttl_minutes', 240)),
            'strict_stock' => (bool) Setting::get('strict_stock', config('restaurant.inventory.strict_stock', true)),
            'inventory_deduction_stage' => Setting::get('inventory_deduction_stage', config('restaurant.inventory.deduction_stage', 'preparing')),
            'auto_approve' => (bool) Setting::get('auto_approve', config('restaurant.order.auto_approve', false)),
            'prep_time_buffer_pct' => (int) Setting::get('prep_time_buffer_pct', 20),
            'staff_meal_include_service' => (bool) Setting::get('staff_meal_include_service', config('restaurant.staff_meals.include_service', false)),
            'staff_meal_over_limit_policy' => Setting::get('staff_meal_over_limit_policy', 'warn'),
            'sms_enabled' => (bool) Setting::get('sms_enabled', false),
            'sms_provider' => Setting::get('sms_provider', 'tweetsms'),
            'sms_api_url' => Setting::get('sms_api_url', 'http://www.tweetsms.ps/api.php'),
            'sms_username' => Setting::get('sms_username', ''),
            'sms_password' => '',
            'sms_sender' => Setting::get('sms_sender', ''),
            'sms_template_forgot_staff' => Setting::get('sms_template_forgot_staff', ''),
            'theme_primary' => $theme['primary'],
            'theme_dark' => $theme['dark'],
            'theme_header' => $theme['header'],
            'theme_accent' => $theme['accent'],
            'theme_menu' => $theme['menu'],
            'theme_header_style' => $theme['header_style'],
            'theme_menu_style' => $theme['menu_style'],
        ];

        $paymentMethods = collect(PaymentMethods::catalog())->map(function (array $meta, string $code) use (&$values) {
            $key = PaymentMethods::settingKey($code);
            $values[$key] = (bool) $meta['enabled'];

            return [
                'code' => $code,
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'description' => $meta['description'],
            ];
        })->values()->all();

        $roles = $this->cappableRoles()->map(function (Role $role) use (&$values, $caps) {
            $defaults = $caps[$role->name] ?? ['percent' => 0, 'fixed' => 0];
            $pctKey = 'discount_cap_'.$role->name.'_pct';
            $fixedKey = 'discount_cap_'.$role->name.'_fixed';
            $values[$pctKey] = (float) Setting::get($pctKey, $defaults['percent']);
            $values[$fixedKey] = (float) Setting::get($fixedKey, $defaults['fixed']);

            return [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label ?: $role->name,
                'custom' => ! $role->is_system,
                'percentKey' => $pctKey,
                'fixedKey' => $fixedKey,
            ];
        })->values()->all();

        $statuses = collect(OrderStatus::cases())->map(function (OrderStatus $status) use (&$values) {
            $labelKey = 'order_status_'.$status->value.'_label';
            $colorKey = 'order_status_'.$status->value.'_color';
            $values[$labelKey] = Setting::get($labelKey, '');
            $values[$colorKey] = Setting::get($colorKey, '');

            return [
                'value' => $status->value,
                'defaultLabel' => $status->defaultLabel(),
                'defaultColor' => $status->defaultColor(),
                'labelKey' => $labelKey,
                'colorKey' => $colorKey,
            ];
        })->values()->all();

        $exchangeRates = CurrencyExchangeRate::query()
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return AdminShell::render('Admin/Settings/Index', [
            'can' => [
                'edit' => $canEdit,
            ],
            'values' => $values,
            'market' => [
                'taxNumberLabel' => MarketProfile::taxNumberLabel(),
                'serviceLabel' => MarketProfile::serviceLabel(),
                'baseCurrency' => $currencies->firstWhere('is_base')?->code ?? config('restaurant.currency'),
            ],
            'paymentMethods' => $paymentMethods,
            'roles' => $roles,
            'statuses' => $statuses,
            'statusColors' => OrderStatus::ALLOWED_COLORS,
            'themeDefaults' => [
                'primary' => $themeDefaults['primary'] ?? '#1f6b50',
                'dark' => $themeDefaults['dark'] ?? '#123f31',
                'header' => $themeDefaults['header'] ?? '#ffffff',
                'accent' => $themeDefaults['accent'] ?? '#d97706',
                'menu' => $themeDefaults['menu'] ?? '#ffffff',
            ],
            'brand' => [
                'logo' => Brand::logoUrl(),
                'favicon' => Brand::faviconUrl(),
                'hasLogo' => Brand::hasCustomLogo(),
                'hasFavicon' => Brand::hasCustomFavicon(),
            ],
            'smsPasswordConfigured' => filled(Setting::get('sms_password')),
            'currencies' => $currencies->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'rate' => (float) $currency->rate_to_base,
                'isBase' => (bool) $currency->is_base,
                'isActive' => (bool) $currency->is_active,
                'updatedAgo' => $currency->rate_updated_at?->diffForHumans(),
                'destroyUrl' => route('admin.currencies.destroy', $currency),
            ])->values()->all(),
            'exchangeRates' => $exchangeRates->map(fn (CurrencyExchangeRate $rate) => [
                'id' => $rate->id,
                'currencyCode' => $rate->currency_code,
                'baseCurrencyCode' => $rate->base_currency_code,
                'rate' => (float) $rate->rate,
                'validFrom' => $rate->valid_from?->toDateString(),
                'validTo' => $rate->valid_to?->toDateString(),
                'source' => $rate->source,
                'note' => $rate->note,
                'destroyUrl' => route('admin.currencies.exchange-rates.destroy', $rate),
            ])->values()->all(),
            'urls' => [
                'index' => route('admin.settings.index'),
                'update' => route('admin.settings.update'),
                'accounting' => route('admin.accounting.index'),
                'roles' => route('admin.roles.index'),
                'lookups' => route('admin.lookups.index').'#group=discount_categories',
                'resetTheme' => route('admin.settings.reset-theme'),
                'testSms' => route('admin.settings.sms.test'),
                'brandUpdate' => route('admin.settings.brand.update'),
                'deleteLogo' => route('admin.settings.brand.delete', 'brand_logo'),
                'deleteFavicon' => route('admin.settings.brand.delete', 'brand_favicon'),
                'currencyStore' => route('admin.currencies.store'),
                'currencyRates' => route('admin.currencies.update-rates'),
                'exchangeRateStore' => route('admin.currencies.exchange-rates.store'),
                'users' => route('admin.users.index'),
            ],
        ]);
    }

    /**
     * Roles that can have a discount cap configured. Owner-level roles
     * (super_admin / partner / admin) are excluded — they're treated as
     * uncapped in OrderDiscountService and showing them here would be
     * misleading. Every other role, system-defined or branch-custom,
     * appears so the admin can set 0 (= no discount) for roles like
     * chef/bartender that don't apply discounts in practice.
     */
    protected function cappableRoles()
    {
        return Role::query()
            ->whereNotIn('name', ['super_admin', 'partner', 'admin'])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'label', 'is_system']);
    }

    public function update(Request $request)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);

        // Build discount-cap rules dynamically from the roles table so a
        // role added later in /admin/roles gets validation automatically.
        // Each role contributes _pct (0..100) and _fixed (>= 0) keys.
        $cappableRoles = $this->cappableRoles();
        $dynamicRules = [];
        foreach ($cappableRoles as $role) {
            $dynamicRules['discount_cap_'.$role->name.'_pct'] = ['sometimes', 'numeric', 'min:0', 'max:100'];
            $dynamicRules['discount_cap_'.$role->name.'_fixed'] = ['sometimes', 'numeric', 'min:0'];
        }

        // Order-status label/color overrides — one pair per OrderStatus case.
        // Empty strings allowed (= revert to default in OrderStatus enum).
        foreach (OrderStatus::cases() as $case) {
            $dynamicRules['order_status_'.$case->value.'_label'] = ['nullable', 'string', 'max:60'];
            $dynamicRules['order_status_'.$case->value.'_color'] = ['nullable', 'in:'.implode(',', OrderStatus::ALLOWED_COLORS)];
        }

        $data = $request->validate(array_merge($dynamicRules, [
            'site_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'bank_transfer_details' => ['nullable', 'string', 'max:1000'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'bank_account_holder' => ['nullable', 'string', 'max:160'],
            'bank_account_number' => ['nullable', 'string', 'max:80'],
            'bank_iban' => ['nullable', 'string', 'max:60'],
            'palpay_wallet_number' => ['nullable', 'string', 'max:40'],
            'jawwal_pay_wallet_number' => ['nullable', 'string', 'max:40'],
            // Customer invoice tax is effective-dated and accountant-owned;
            // it can only be changed from the accounting centre.
            'customer_tax_display' => ['required', 'in:exclusive,inclusive'],
            'service_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'service_enabled' => ['sometimes', 'boolean'],
            'currency_symbol' => ['required', 'string', 'max:10'],
            'customer_currency_switcher' => ['sometimes', 'boolean'],
            'customer_cancel_window_seconds' => ['required', 'integer', 'min:0', 'max:900'],
            'session_ttl_minutes' => ['required', 'integer', 'min:30', 'max:1440'],
            'strict_stock' => ['sometimes', 'boolean'],
            'inventory_deduction_stage' => ['sometimes', 'in:approve,preparing,ready,served'],
            // Auto-approve: when on, a waiter/QR order skips the manual
            // approval step and goes straight to the kitchen (deducting stock).
            'auto_approve' => ['sometimes', 'boolean'],
            // Staff meals: branch-level toggle for whether the service
            // charge stays on the employee's tab. Default off.
            'staff_meal_include_service' => ['sometimes', 'boolean'],
            // Over-limit policy when a staff order would push the employee
            // past their hard `meal_debt_ceiling`. Tightest → loosest:
            //   block            → throw, no charge created
            //   require_approval → block unless a manager PIN/approves
            //   warn             → record + log warning (default)
            //   allow_log        → record silently (legacy behaviour)
            'staff_meal_over_limit_policy' => ['sometimes', 'in:allow_log,warn,require_approval,block'],
            // Theme color settings
            'theme_primary' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_dark' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_menu' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header_style' => ['nullable', 'in:light,dark,color'],
            'theme_menu_style' => ['nullable', 'in:light,dark,brand'],
            // Discount caps per role — see $dynamicRules above. Validation
            // is generated from the roles table so any custom role added
            // in /admin/roles automatically gets its own cap fields.
            // Prep-time buffer: extra % piled on top of max(item prep_time)
            // when computing the customer-facing ETA + the kitchen's
            // "should be ready by" stamp. 20% is the rule-of-thumb default;
            // capped at 200 so a typo can't quote "ready in 4 hours".
            'prep_time_buffer_pct' => ['sometimes', 'integer', 'min:0', 'max:200'],
            // SMS provider (TweetSMS by default). Credentials are stored
            // in the settings table; the password is encrypted with
            // Laravel's Crypt facade before write so a DB leak doesn't
            // expose the provider account. An empty password on submit
            // means "keep the existing one" — the form pre-fills with a
            // masked placeholder for that reason.
            'sms_enabled' => ['sometimes', 'boolean'],
            'sms_provider' => ['nullable', 'string', 'max:40'],
            'sms_api_url' => ['nullable', 'url', 'max:255'],
            'sms_username' => ['nullable', 'string', 'max:120'],
            'sms_password' => ['nullable', 'string', 'max:200'],
            'sms_sender' => ['nullable', 'string', 'max:40'],
            // SMS body templates. Free text with placeholders ({brand},
            // {password}, {login_url}). Empty → use the controller's
            // built-in Arabic default so a fresh install isn't broken.
            'sms_template_forgot_staff' => ['nullable', 'string', 'max:500'],
            // Payment-method toggles. One boolean per method known to
            // PaymentMethods::CATALOG; missing = use the catalog default.
            'payment_method_cash_enabled' => ['sometimes', 'boolean'],
            'payment_method_transfer_enabled' => ['sometimes', 'boolean'],
            'payment_method_card_enabled' => ['sometimes', 'boolean'],
            'payment_method_palpay_enabled' => ['sometimes', 'boolean'],
            'payment_method_jawwal_pay_enabled' => ['sometimes', 'boolean'],
        ]));

        $paymentMethodKeys = [
            'payment_method_cash_enabled',
            'payment_method_transfer_enabled',
        ];

        if ($request->hasAny($paymentMethodKeys)
            && ! collect($paymentMethodKeys)->contains(fn (string $key) => $request->boolean($key))) {
            return back()
                ->withErrors(['payment_method_cash_enabled' => 'يجب تفعيل طريقة دفع واحدة على الأقل حتى لا يتعطل الكاشير.'])
                ->withInput();
        }

        // Encrypt the SMS password before persisting. An empty submit
        // keeps the existing value — otherwise editing any other field
        // would wipe the password every time.
        if (array_key_exists('sms_password', $data)) {
            if ($data['sms_password'] === null || $data['sms_password'] === '') {
                unset($data['sms_password']);
            } else {
                $data['sms_password'] = Crypt::encryptString($data['sms_password']);
            }
        }

        $meta = [
            'site_name' => ['general', 'string'],
            'legal_name' => ['general', 'string'],
            'tax_number' => ['general', 'string'],
            'receipt_footer' => ['billing', 'string'],
            'bank_transfer_details' => ['billing', 'string'],
            'bank_name' => ['payment_destinations', 'string'],
            'bank_account_holder' => ['payment_destinations', 'string'],
            'bank_account_number' => ['payment_destinations', 'string'],
            'bank_iban' => ['payment_destinations', 'string'],
            'palpay_wallet_number' => ['payment_destinations', 'string'],
            'jawwal_pay_wallet_number' => ['payment_destinations', 'string'],
            'currency_symbol' => ['billing', 'string'],
            'customer_tax_display' => ['billing', 'string'],
            'service_rate' => ['billing', 'float'],
            'service_enabled' => ['billing', 'bool'],
            'customer_currency_switcher' => ['customer', 'bool'],
            'customer_cancel_window_seconds' => ['customer', 'int'],
            'session_ttl_minutes' => ['customer', 'int'],
            'strict_stock' => ['inventory', 'bool'],
            'inventory_deduction_stage' => ['inventory', 'string'],
            'auto_approve' => ['orders', 'bool'],
            'staff_meal_include_service' => ['staff_meals', 'bool'],
            'staff_meal_over_limit_policy' => ['staff_meals', 'string'],
            'theme_primary' => ['theme', 'string'],
            'theme_dark' => ['theme', 'string'],
            'theme_header' => ['theme', 'string'],
            'theme_accent' => ['theme', 'string'],
            'theme_menu' => ['theme', 'string'],
            'theme_header_style' => ['theme', 'string'],
            'theme_menu_style' => ['theme', 'string'],
            // discount_cap_{role}_* meta is injected from $cappableRoles
            // right after this array — same dynamic source as the rules.
            'prep_time_buffer_pct' => ['general', 'int'],
            'sms_enabled' => ['sms', 'bool'],
            'sms_provider' => ['sms', 'string'],
            'sms_api_url' => ['sms', 'string'],
            'sms_username' => ['sms', 'string'],
            'sms_password' => ['sms', 'string'],
            'sms_sender' => ['sms', 'string'],
            'sms_template_forgot_staff' => ['sms', 'string'],
            'payment_method_cash_enabled' => ['payments', 'bool'],
            'payment_method_transfer_enabled' => ['payments', 'bool'],
            'payment_method_card_enabled' => ['payments', 'bool'],
            'payment_method_palpay_enabled' => ['payments', 'bool'],
            'payment_method_jawwal_pay_enabled' => ['payments', 'bool'],
        ];

        foreach ($cappableRoles as $role) {
            $meta['discount_cap_'.$role->name.'_pct'] = ['discounts', 'float'];
            $meta['discount_cap_'.$role->name.'_fixed'] = ['discounts', 'float'];
        }

        // Order-status meta + allow clearing to revert to defaults.
        $clearable = [
            'legal_name', 'tax_number', 'receipt_footer', 'bank_transfer_details',
            'bank_name', 'bank_account_holder', 'bank_account_number', 'bank_iban',
            'palpay_wallet_number', 'jawwal_pay_wallet_number',
            'sms_provider', 'sms_api_url', 'sms_username', 'sms_sender',
            'sms_template_forgot_staff',
        ];
        foreach (OrderStatus::cases() as $case) {
            $meta['order_status_'.$case->value.'_label'] = ['order_status', 'string'];
            $meta['order_status_'.$case->value.'_color'] = ['order_status', 'string'];
            $clearable[] = 'order_status_'.$case->value.'_label';
            $clearable[] = 'order_status_'.$case->value.'_color';
        }

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

    /**
     * Ping the SMS provider with the saved credentials and report
     * back. Cheapest correct-config proof: a `chk_balance` call.
     * Returns the balance as text so the admin sees a non-zero
     * number — a successful auth + healthy account in one round-trip.
     */
    public function testSms(SmsService $sms)
    {
        abort_unless(auth()->user()->hasAnyRole(['super_admin', 'admin']), 403);
        try {
            $balance = $sms->balance();

            return back()->with('success', "تم الاتصال بنجاح. الرصيد المتاح: {$balance}");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
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
            'brand_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'brand_favicon' => ['nullable', 'image', 'mimes:png,ico,jpg,webp,svg', 'max:512'],
        ], [], [
            'brand_logo' => 'شعار البرنامج',
            'brand_favicon' => 'أيقونة التبويب',
        ]);

        foreach (['brand_logo', 'brand_favicon'] as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

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
