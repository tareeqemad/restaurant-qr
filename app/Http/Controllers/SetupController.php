<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\Setting;
use App\Models\User;
use App\Services\DemoResetService;
use App\Services\ExchangeRateService;
use App\Support\FirstRunSetup;
use App\Support\RuntimeConfig;
use App\Support\ThemePalette;
use Database\Seeders\SystemSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class SetupController extends Controller
{
    public function show(Request $request)
    {
        if (! FirstRunSetup::wizardAvailable()) {
            return $this->completedRedirect();
        }

        if (! FirstRunSetup::canAccess($request->user())) {
            return redirect()->route('login')->with('status', __('setup.auth_required'));
        }

        $willResetDemoData = FirstRunSetup::willResetDemoData();

        return view('setup.show', [
            'hasUsers' => FirstRunSetup::hasUsers(),
            'hasBranches' => FirstRunSetup::hasBranches(),
            'willResetDemoData' => $willResetDemoData,
            'isDemoTrial' => FirstRunSetup::isDemoTrial(),
            'profile' => config('market.profiles.'.config('market.profile'), []),
            'currentProfile' => config('market.profile', 'palestine'),
            'defaults' => $this->defaults($willResetDemoData),
        ]);
    }

    public function store(Request $request, DemoResetService $reset)
    {
        if (! FirstRunSetup::wizardAvailable()) {
            return $this->completedRedirect();
        }

        if (! FirstRunSetup::canAccess($request->user())) {
            abort(403);
        }

        $willResetDemoData = FirstRunSetup::willResetDemoData();
        $setupBranch = $willResetDemoData ? null : $this->setupBranch();
        $data = $request->validate($this->rules($willResetDemoData, $setupBranch));
        $data = $this->normalizeAccountingSetup($data);
        $profile = $data['market_profile'];
        $license = $this->licenseSettings();

        config(['market.profile' => $profile]);

        if ($willResetDemoData) {
            $this->clearUploadedBrandAssets();
            $reset->reset(null, wipeBusinessReferenceData: true);
            Setting::query()->delete();
            Cache::flush();
        }

        Artisan::call('db:seed', ['--class' => SystemSeeder::class, '--force' => true]);

        $user = DB::transaction(function () use ($data, $request, $license) {
            $this->putSetting('market_profile', $data['market_profile'], 'system');

            $this->putSetting('site_name', $data['restaurant_name'], 'general');
            $this->putOptionalSetting('legal_name', $data['legal_name'] ?? null, 'general');
            $this->putOptionalSetting('tax_number', $data['tax_number'] ?? null, 'general');
            $this->putOptionalSetting('receipt_footer', $data['receipt_footer'] ?? null, 'billing');
            $this->putSetting('currency_symbol', $data['currency_symbol'], 'billing');
            $this->putSetting('sales_currency', $data['sales_currency'], 'billing');
            $this->putSetting('accounting_base_currency', $data['accounting_base_currency'], 'accounting');
            $this->putSetting('accounting_currency_symbol', $data['accounting_currency_symbol'], 'accounting');
            $this->putSetting('sales_to_accounting_rate', $data['sales_to_accounting_rate'], 'accounting', 'float');
            $this->putSetting('fiscal_year_start_month', $data['fiscal_year_start_month'], 'accounting', 'int');
            $this->putSetting('fiscal_year_start_day', $data['fiscal_year_start_day'], 'accounting', 'int');
            $this->syncCurrencies($data);
            $this->putSetting('tax_enabled', (bool) ($data['tax_enabled'] ?? false), 'billing', 'bool');
            $this->putSetting('tax_rate', $data['tax_rate'], 'billing', 'float');
            $this->putSetting('customer_tax_display', $data['customer_tax_display'], 'billing');
            $this->putSetting('service_enabled', (bool) ($data['service_enabled'] ?? false), 'billing', 'bool');
            $this->putSetting('service_rate', $data['service_rate'], 'billing', 'float');

            foreach (['theme_primary', 'theme_dark', 'theme_header', 'theme_accent', 'theme_menu'] as $key) {
                $this->putSetting($key, $data[$key], 'theme');
            }
            $this->putSetting('theme_header_style', $data['theme_header_style'], 'theme');
            $this->putSetting('theme_menu_style', $data['theme_menu_style'], 'theme');

            foreach (['brand_logo', 'brand_favicon'] as $field) {
                if ($request->hasFile($field)) {
                    $this->putSetting($field, $this->storeBrandAsset($request, $field), 'brand');
                }
            }

            $this->putOptionalSetting('menu_base_url', $data['menu_base_url'] ?? null, 'connectivity');
            $this->putSetting('strict_stock', (bool) ($data['strict_stock'] ?? false), 'inventory', 'bool');
            $this->putSetting('inventory_deduction_stage', $data['inventory_deduction_stage'], 'inventory');
            $this->putSetting('customer_cancel_window_seconds', $data['customer_cancel_window_seconds'], 'customer', 'int');
            $this->putSetting('session_ttl_minutes', $data['session_ttl_minutes'], 'customer', 'int');

            $this->putSetting('sync_enabled', (bool) ($data['sync_enabled'] ?? false), 'sync', 'bool');
            $this->putSetting('sync_role', $data['sync_role'], 'sync');
            $this->putOptionalSetting('sync_cloud_url', $data['sync_cloud_url'] ?? null, 'sync');
            $this->putOptionalSetting('sync_token', $data['sync_token'] ?? null, 'sync');

            $this->putSetting('license_enabled', $license['enabled'], 'license', 'bool');
            $this->putSetting('license_role', $license['role'], 'license');
            $this->putOptionalSetting('license_cloud_url', $license['cloud_url'], 'license');
            $this->putOptionalSetting('license_key', $license['key'], 'license');

            $branchPayload = [
                'code' => $data['branch_code'],
                'name' => $data['branch_name'],
                'name_en' => $data['branch_name_en'] ?? null,
                'phone' => $data['branch_phone'] ?? null,
                'email' => $data['branch_email'] ?? null,
                'city' => $data['branch_city'] ?? null,
                'address' => $data['branch_address'] ?? null,
                'is_active' => true,
                'display_order' => 1,
                'settings' => [
                    'delivery_estimated_minutes' => (int) $data['delivery_estimated_minutes'],
                    'delivery_fee' => (float) $data['delivery_fee'],
                    'prep_buffer_minutes' => (int) $data['prep_buffer_minutes'],
                ],
            ];

            $branch = Branch::create($branchPayload);
            $this->createInitialFiscalYear($branch, $data);
            $syncBranchUuid = trim((string) ($data['sync_branch_uuid'] ?? '')) ?: (string) ($branch->uuid ?? '');
            $this->putOptionalSetting('sync_branch_uuid', $syncBranchUuid, 'sync');

            $createdUser = User::create([
                'name' => $data['admin_name'],
                'username' => $data['admin_username'],
                'email' => $data['admin_email'] ?? null,
                'phone' => $data['admin_phone'] ?? null,
                'role' => UserRole::SuperAdmin->value,
                'status' => 'active',
                'password' => Hash::make($data['admin_password']),
            ]);

            $this->putSetting('setup_completed', true, 'system', 'bool');
            $this->putSetting('setup_completed_at', now()->toISOString(), 'system');

            session(['active_branch_id' => $branch->id]);

            return $createdUser;
        });

        RuntimeConfig::apply();

        if ($user) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('admin.dashboard')->with('success', __('setup.completed_notice'));
    }

    private function rules(bool $willResetDemoData, ?Branch $setupBranch): array
    {
        $configuredProfile = config('market.profile', 'palestine');

        $branchCodeRules = ['required', 'alpha_dash', 'max:32'];
        if (! $willResetDemoData) {
            $branchCodeRules[] = Rule::unique('branches', 'code')->ignore($setupBranch?->id);
        }

        $usernameRules = ['required', 'alpha_dash', 'max:50'];
        $emailRules = ['nullable', 'email', 'max:120'];
        if (! $willResetDemoData) {
            $usernameRules[] = Rule::unique('users', 'username');
            $emailRules[] = Rule::unique('users', 'email');
        }

        $rules = [
            'market_profile' => ['required', Rule::in([$configuredProfile])],
            'restaurant_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'currency_symbol' => ['required', 'string', 'max:10'],
            'sales_currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'accounting_base_currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'accounting_currency_symbol' => ['required', 'string', 'max:10'],
            'sales_to_accounting_rate' => ['required', 'numeric', 'min:0.000001', 'max:999999'],
            'fiscal_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'fiscal_year_start_day' => ['required', 'integer', 'min:1', 'max:31'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'customer_tax_display' => ['required', 'in:exclusive,inclusive'],
            'service_enabled' => ['sometimes', 'boolean'],
            'service_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'theme_primary' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_dark' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_menu' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_header_style' => ['required', 'in:light,dark,color'],
            'theme_menu_style' => ['required', 'in:light,dark,brand'],
            'brand_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'brand_favicon' => ['nullable', 'image', 'mimes:png,ico,jpg,webp,svg', 'max:512'],
            'branch_code' => $branchCodeRules,
            'branch_name' => ['required', 'string', 'max:120'],
            'branch_name_en' => ['nullable', 'string', 'max:120'],
            'branch_phone' => ['nullable', 'string', 'max:50'],
            'branch_email' => ['nullable', 'email', 'max:120'],
            'branch_city' => ['nullable', 'string', 'max:80'],
            'branch_address' => ['nullable', 'string', 'max:500'],
            'delivery_estimated_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:9999'],
            'prep_buffer_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'menu_base_url' => ['nullable', 'url', 'max:255'],
            'strict_stock' => ['sometimes', 'boolean'],
            'inventory_deduction_stage' => ['required', 'in:approve,preparing,ready,served'],
            'customer_cancel_window_seconds' => ['required', 'integer', 'min:0', 'max:900'],
            'session_ttl_minutes' => ['required', 'integer', 'min:30', 'max:1440'],
            'sync_enabled' => ['sometimes', 'boolean'],
            'sync_role' => ['required', 'in:standalone,branch,cloud'],
            'sync_cloud_url' => ['nullable', 'url', 'max:255'],
            'sync_token' => ['nullable', 'string', 'max:255'],
            'sync_branch_uuid' => ['nullable', 'string', 'max:80'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_username' => $usernameRules,
            'admin_email' => $emailRules,
            'admin_phone' => ['nullable', 'string', 'max:50'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        return $rules;
    }

    private function defaults(bool $willResetDemoData = false): array
    {
        $isUs = config('market.profile') === 'us';
        $branch = $willResetDemoData ? null : $this->setupBranch();
        $themeDefaults = config('restaurant.theme', []);
        $license = $this->licenseSettings();
        $themePrimary = $themeDefaults['primary'] ?? '#164c37';
        $themeDark = $themeDefaults['dark'] ?? '#0f2d22';
        $themeHeader = $themeDefaults['header'] ?? $themePrimary;
        $themeAccent = $themeDefaults['accent'] ?? '#b97818';
        $themeMenu = $themeDefaults['menu'] ?? '#f7f8f5';
        $defaultCurrency = strtoupper((string) config('restaurant.currency', $isUs ? 'USD' : 'ILS'));

        return [
            'restaurant_name' => config('restaurant.name', $isUs ? __('setup.defaults.restaurant_us') : __('setup.defaults.restaurant_palestine')),
            'receipt_footer' => Setting::get('receipt_footer'),
            'currency_symbol' => config('restaurant.currency_symbol', $isUs ? '$' : '₪'),
            'sales_currency' => Setting::get('sales_currency', $defaultCurrency),
            'accounting_base_currency' => Setting::get('accounting_base_currency', $defaultCurrency),
            'accounting_currency_symbol' => Setting::get('accounting_currency_symbol', $this->currencySymbolForCode($defaultCurrency, config('restaurant.currency_symbol', $isUs ? '$' : 'â‚ھ'))),
            'sales_to_accounting_rate' => Setting::get('sales_to_accounting_rate', 1),
            'fiscal_year_start_month' => Setting::get('fiscal_year_start_month', 1),
            'fiscal_year_start_day' => Setting::get('fiscal_year_start_day', 1),
            'tax_rate' => config('restaurant.tax.rate', $isUs ? 0 : 16),
            'tax_enabled' => config('restaurant.tax.enabled', true),
            'service_rate' => config('restaurant.service_charge.rate', $isUs ? 0 : 10),
            'service_enabled' => config('restaurant.service_charge.enabled', false),
            'theme_primary' => ThemePalette::normalizeHex(Setting::get('theme_primary', $themePrimary), $themePrimary),
            'theme_dark' => ThemePalette::normalizeHex(Setting::get('theme_dark', $themeDark), $themeDark),
            'theme_header' => ThemePalette::normalizeHex(Setting::get('theme_header', $themeHeader), $themeHeader),
            'theme_accent' => ThemePalette::normalizeHex(Setting::get('theme_accent', $themeAccent), $themeAccent),
            'theme_menu' => ThemePalette::normalizeHex(Setting::get('theme_menu', $themeMenu), $themeMenu),
            'theme_header_style' => Setting::get('theme_header_style', $themeDefaults['header_style'] ?? 'color'),
            'theme_menu_style' => Setting::get('theme_menu_style', $themeDefaults['menu_style'] ?? 'brand'),
            'branch_code' => $branch?->code ?? 'main',
            'branch_name' => $branch?->name ?? ($isUs ? __('setup.defaults.branch_us') : __('setup.defaults.branch_palestine')),
            'branch_name_en' => $branch?->name_en ?? 'Main Branch',
            'branch_phone' => $branch?->phone,
            'branch_email' => $branch?->email,
            'branch_city' => $branch?->city,
            'branch_address' => $branch?->address,
            'delivery_estimated_minutes' => $branch?->deliveryMinutes() ?? 30,
            'delivery_fee' => $branch?->deliveryFee() ?? 0,
            'prep_buffer_minutes' => $branch?->prepBufferMinutes() ?? 5,
            'menu_base_url' => config('restaurant.menu_base_url'),
            'inventory_deduction_stage' => config('restaurant.inventory.deduction_stage', 'approve'),
            'customer_cancel_window_seconds' => config('restaurant.order.customer_cancel_window_seconds', 120),
            'session_ttl_minutes' => config('restaurant.order.session_ttl_minutes', 240),
            'sync_role' => config('sync.role', 'standalone'),
            'sync_enabled' => (bool) config('sync.enabled', false),
            'sync_cloud_url' => config('sync.cloud_url'),
            'sync_token' => config('sync.token'),
            'sync_branch_uuid' => config('sync.branch_uuid'),
            'license_role' => $license['role'],
            'license_enabled' => $license['enabled'],
            'license_cloud_url' => $license['cloud_url'],
            'license_key' => $license['key'],
        ];
    }

    private function normalizeAccountingSetup(array $data): array
    {
        $data['sales_currency'] = strtoupper(trim((string) $data['sales_currency']));
        $data['accounting_base_currency'] = strtoupper(trim((string) $data['accounting_base_currency']));
        $data['sales_to_accounting_rate'] = (float) $data['sales_to_accounting_rate'];
        $data['fiscal_year_start_month'] = (int) $data['fiscal_year_start_month'];
        $data['fiscal_year_start_day'] = (int) $data['fiscal_year_start_day'];

        if ($data['sales_currency'] === $data['accounting_base_currency']) {
            $data['sales_to_accounting_rate'] = 1.0;
            $data['accounting_currency_symbol'] = $data['currency_symbol'];
        }

        if (! checkdate($data['fiscal_year_start_month'], $data['fiscal_year_start_day'], 2025)) {
            throw ValidationException::withMessages([
                'fiscal_year_start_day' => __('setup.validation.invalid_fiscal_year_start'),
            ]);
        }

        return $data;
    }

    private function syncCurrencies(array $data): void
    {
        $baseCode = $data['accounting_base_currency'];
        $salesCode = $data['sales_currency'];

        Currency::query()->update(['is_base' => false]);

        Currency::updateOrCreate(
            ['code' => $baseCode],
            [
                'name' => $this->currencyName($baseCode),
                'symbol' => $data['accounting_currency_symbol'],
                'rate_to_base' => 1,
                'is_base' => true,
                'is_active' => true,
                'display_order' => 1,
                'rate_updated_at' => now(),
            ],
        );

        if ($salesCode !== $baseCode) {
            Currency::updateOrCreate(
                ['code' => $salesCode],
                [
                    'name' => $this->currencyName($salesCode),
                    'symbol' => $data['currency_symbol'],
                    'rate_to_base' => $data['sales_to_accounting_rate'],
                    'is_base' => false,
                    'is_active' => true,
                    'display_order' => 2,
                    'rate_updated_at' => now(),
                ],
            );

            app(ExchangeRateService::class)->record(
                currencyCode: $salesCode,
                baseCurrencyCode: $baseCode,
                rate: (float) $data['sales_to_accounting_rate'],
                validFrom: now()->toDateString(),
                validTo: null,
                createdBy: auth()->id(),
                source: 'setup',
                note: 'Initial first-run exchange rate',
            );
        }

        config([
            'restaurant.currency' => $salesCode,
            'restaurant.currency_symbol' => $data['currency_symbol'],
        ]);
    }

    private function createInitialFiscalYear(Branch $branch, array $data): void
    {
        $asOf = Carbon::now();
        $startsOn = Carbon::create($asOf->year, $data['fiscal_year_start_month'], $data['fiscal_year_start_day'])->startOfDay();

        if ($asOf->lt($startsOn)) {
            $startsOn->subYear();
        }

        $endsOn = $startsOn->copy()->addYear()->subDay();
        $name = $startsOn->year === $endsOn->year
            ? 'FY '.$startsOn->year
            : 'FY '.$startsOn->year.'/'.$endsOn->year;

        FiscalYear::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
            ],
            [
                'name' => $name,
                'status' => 'open',
            ],
        );
    }

    private function currencyName(string $code): string
    {
        return match (strtoupper($code)) {
            'USD' => 'US Dollar',
            'ILS' => 'Israeli Shekel',
            'JOD' => 'Jordanian Dinar',
            'EUR' => 'Euro',
            'GBP' => 'British Pound',
            default => strtoupper($code),
        };
    }

    private function currencySymbolForCode(string $code, string $fallback): string
    {
        return match (strtoupper($code)) {
            'USD' => '$',
            'ILS' => '₪',
            'JOD' => 'د.أ',
            'EUR' => '€',
            'GBP' => '£',
            default => $fallback,
        };
    }

    /**
     * License terms are provider-controlled. Setup should persist the
     * delivered build configuration, not accept customer-edited license values.
     *
     * @return array{enabled: bool, role: string, cloud_url: ?string, key: ?string}
     */
    private function licenseSettings(): array
    {
        $role = (string) config('license.role', 'standalone');
        if (! in_array($role, ['standalone', 'branch', 'cloud'], true)) {
            $role = 'standalone';
        }

        $stringOrNull = static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;

        return [
            'enabled' => (bool) config('license.enabled', false),
            'role' => $role,
            'cloud_url' => $stringOrNull(config('license.cloud_url')),
            'key' => $stringOrNull(config('license.key')),
        ];
    }

    private function completedRedirect()
    {
        return Auth::check()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('login');
    }

    private function setupBranch(): ?Branch
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->first()
            ?? Branch::query()->orderBy('id')->first();
    }

    private function putOptionalSetting(string $key, mixed $value, string $group, string $type = 'string'): void
    {
        if ($value === null || $value === '') {
            Setting::query()->where('key', $key)->delete();
            Cache::forget('setting.'.$key);

            return;
        }

        $this->putSetting($key, $value, $group, $type);
    }

    private function putSetting(string $key, mixed $value, string $group, string $type = 'string'): void
    {
        Setting::put($key, $value, $group, $type);
    }

    private function clearUploadedBrandAssets(): void
    {
        foreach (['brand_logo', 'brand_favicon'] as $key) {
            $path = trim((string) Setting::get($key, ''));
            if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
                continue;
            }

            $normalized = ltrim($path, '/');
            $normalized = str_starts_with($normalized, 'storage/')
                ? substr($normalized, strlen('storage/'))
                : $normalized;

            Storage::disk('public')->delete($normalized);
        }
    }

    private function storeBrandAsset(Request $request, string $field): string
    {
        $path = $request->file($field)->store('brand', 'public');
        Storage::disk('public')->setVisibility($path, 'public');
        $this->makePubliclyReadable(Storage::disk('public')->path($path));
        clearstatcache();

        return $path;
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
