<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Helpers\Brand;
use App\Models\Branch;
use App\Models\BusinessOwner;
use App\Models\Currency;
use App\Models\CustomerSalesTaxRate;
use App\Models\FiscalYear;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\DemoResetService;
use App\Services\StaffSetupService;
use App\Support\FirstRunSetup;
use App\Support\PhoneNumber;
use App\Support\RuntimeConfig;
use Carbon\Carbon;
use Database\Seeders\SystemSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SetupController extends Controller
{
    /** @var array<int,string> */
    private const TEAM_ROLES = [
        UserRole::Manager->value,
        UserRole::Accountant->value,
        UserRole::Waiter->value,
        UserRole::Chef->value,
        UserRole::Bartender->value,
        UserRole::Cashier->value,
    ];

    public function show(Request $request)
    {
        if (! FirstRunSetup::wizardAvailable()) {
            return $this->completedRedirect();
        }

        if (! FirstRunSetup::canAccess($request->user())) {
            return redirect()->route('login')->with('status', __('setup.auth_required'));
        }

        $isDemoTrial = FirstRunSetup::isDemoTrial();
        $branch = $this->setupBranch();

        Inertia::setRootView('inertia');

        return Inertia::render('Setup/Show', [
            'brand' => [
                'name' => Brand::name(),
                'logo' => Brand::logoUrl(),
            ],
            'mode' => $isDemoTrial ? 'demo' : 'fresh',
            'defaults' => $this->visibleDefaults($request->user(), $branch, $isDemoTrial),
            'summary' => $this->handoverSummary($branch),
            'roles' => $this->teamRoleCards(),
            'routes' => [
                'store' => route('setup.store'),
                'continueDemo' => $isDemoTrial && Auth::check() ? route('admin.dashboard') : route('login'),
            ],
        ]);
    }

    public function store(
        Request $request,
        DemoResetService $reset,
        StaffSetupService $staffSetup,
    ) {
        if (! FirstRunSetup::wizardAvailable()) {
            return $this->completedRedirect();
        }

        if (! FirstRunSetup::canAccess($request->user())) {
            abort(403);
        }

        $this->normalizePhones($request);

        $willResetDemoData = FirstRunSetup::willResetDemoData();
        $setupBranch = $this->setupBranch();
        $data = $request->validate($this->rules($willResetDemoData, $setupBranch, $request->user()));
        $actor = $request->user();

        if ($willResetDemoData) {
            abort_unless($setupBranch, 422, 'تعذر تحديد فرع المنيو الذي سيُحفظ أثناء التجهيز.');
            $this->clearUploadedBrandAssets();
            $reset->reset(
                keepUser: $actor,
                wipeBusinessReferenceData: true,
                preserveBranchId: $setupBranch->id,
            );
            Setting::query()->delete();
            Cache::flush();
        }

        Artisan::call('db:seed', ['--class' => SystemSeeder::class, '--force' => true]);

        [$owner, $credentials] = DB::transaction(function () use (
            $data,
            $request,
            $actor,
            $setupBranch,
            $willResetDemoData,
            $staffSetup,
        ) {
            $this->writeCleanSettings($data, $request);

            $branch = $willResetDemoData
                ? Branch::withoutGlobalScopes()->findOrFail($setupBranch->id)
                : new Branch;

            $branch->fill([
                'code' => $data['branch_code'],
                'name' => $data['branch_name'],
                'phone' => $data['branch_phone'] ?: null,
                'email' => null,
                'city' => $data['branch_city'] ?: null,
                'address' => $data['branch_address'] ?: null,
                'is_active' => true,
                'display_order' => 1,
                'settings' => ['prep_buffer_minutes' => 5],
            ])->save();

            $branch->legalProfile()->updateOrCreate(
                ['branch_id' => $branch->id],
                [
                    'registered_name' => $data['legal_name'] ?: null,
                    'tax_number' => $data['tax_number'] ?: null,
                    'commercial_registration_number' => $data['commercial_registration_number'] ?: null,
                    'municipal_license_number' => $data['municipal_license_number'] ?: null,
                    'invoice_phone' => $data['branch_phone'] ?: null,
                    'invoice_address' => $data['branch_address'] ?: null,
                    'created_by_user_id' => $actor?->id,
                    'updated_by_user_id' => $actor?->id,
                ],
            );

            $businessOwner = BusinessOwner::create([
                'owner_type' => $data['business_owner_type'],
                'name' => $data['business_owner_name'],
                'national_id' => $data['business_owner_national_id'] ?: null,
                'tax_number' => $data['tax_number'] ?: null,
                'phone' => $data['business_owner_phone'] ?: null,
                'is_active' => true,
                'created_by_user_id' => $actor?->id,
            ]);
            $branch->owners()->attach($businessOwner->id, [
                'uuid' => (string) Str::ulid(),
                'ownership_percentage' => $data['business_owner_percentage'],
                'title' => $data['business_owner_type'] === 'company' ? 'الجهة المالكة' : 'مالك',
                'is_primary' => true,
                'is_authorized_signatory' => true,
                'starts_on' => now()->toDateString(),
            ]);

            $this->ensureBranchSkeleton($branch);
            $this->createInitialFiscalYear($branch);

            $owner = $actor ?: new User;
            $owner->fill([
                'name' => $data['admin_name'],
                'username' => $data['admin_username'],
                'email' => $data['admin_email'] ?: null,
                'phone' => $data['admin_phone'] ?: null,
                'role' => UserRole::SuperAdmin->value,
                'station_id' => null,
                'status' => 'active',
                'password' => $data['admin_password'],
            ]);
            $owner->deleted_at = null;
            $owner->save();
            $owner->branches()->sync([
                $branch->id => ['is_primary' => true, 'joined_at' => now()],
            ]);

            session(['active_branch_id' => $branch->id]);

            $credentials = [];
            foreach ($data['staff'] ?? [] as $member) {
                $created = $staffSetup->create([
                    'name' => $member['name'],
                    'phone' => $member['phone'] ?: null,
                    'role' => $member['role'],
                    'branch_id' => $branch->id,
                ]);
                $credentials[] = [
                    'name' => $created['user']->name,
                    'role' => UserRole::from($created['user']->role)->label(),
                    'username' => $created['user']->username,
                    'password' => $created['password'],
                ];
            }

            Setting::put('setup_completed', true, 'system', 'bool');
            Setting::put('setup_completed_at', now()->toISOString(), 'system');

            return [$owner, $credentials];
        });

        RuntimeConfig::apply();
        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()
            ->route('setup.complete')
            ->with('setup_credentials', $credentials);
    }

    public function complete(Request $request)
    {
        if (! Auth::check() || ! FirstRunSetup::completed()) {
            return redirect()->route('login');
        }

        if (! $request->session()->has('setup_credentials')) {
            return redirect()->route('admin.dashboard');
        }

        Inertia::setRootView('inertia');

        return Inertia::render('Setup/Complete', [
            'brand' => ['name' => Brand::name(), 'logo' => Brand::logoUrl()],
            'owner' => ['name' => $request->user()->name, 'username' => $request->user()->username],
            'credentials' => $request->session()->get('setup_credentials', []),
            'dashboardUrl' => route('admin.dashboard'),
        ]);
    }

    private function rules(bool $willResetDemoData, ?Branch $setupBranch, ?User $actor): array
    {
        $branchCode = ['required', 'alpha_dash', 'max:32'];
        $username = ['required', 'alpha_dash', 'max:50'];
        $email = ['nullable', 'email', 'max:120'];

        if ($willResetDemoData) {
            $branchCode[] = Rule::unique('branches', 'code')->ignore($setupBranch?->id);
            $username[] = Rule::unique('users', 'username')->ignore($actor?->id);
            $email[] = Rule::unique('users', 'email')->ignore($actor?->id);
        } else {
            $branchCode[] = Rule::unique('branches', 'code');
            $username[] = Rule::unique('users', 'username');
            $email[] = Rule::unique('users', 'email');
        }

        return [
            'restaurant_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'commercial_registration_number' => ['nullable', 'string', 'max:100'],
            'municipal_license_number' => ['nullable', 'string', 'max:100'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'brand_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'brand_favicon' => ['nullable', 'image', 'mimes:png,ico,jpg,webp,svg', 'max:512'],
            'branch_code' => $branchCode,
            'branch_name' => ['required', 'string', 'max:120'],
            'branch_phone' => ['nullable', 'string', 'max:20'],
            'branch_city' => ['nullable', 'string', 'max:80'],
            'branch_address' => ['nullable', 'string', 'max:500'],
            'business_owner_type' => ['required', Rule::in(['person', 'company'])],
            'business_owner_name' => ['required', 'string', 'min:2', 'max:191'],
            'business_owner_national_id' => ['nullable', 'string', 'max:80'],
            'business_owner_phone' => ['nullable', 'string', 'size:10', 'regex:/^(?:056|059)\d{7}$/'],
            'business_owner_percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'admin_username' => $username,
            'admin_email' => $email,
            'admin_phone' => ['nullable', 'string', 'size:10', 'regex:/^(?:056|059)\d{7}$/'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'staff' => ['sometimes', 'array', 'max:12'],
            'staff.*.name' => ['required', 'string', 'min:2', 'max:120'],
            'staff.*.phone' => ['nullable', 'string', 'size:10', 'regex:/^(?:056|059)\d{7}$/'],
            'staff.*.role' => ['required', Rule::in(self::TEAM_ROLES)],
            'confirm_reset' => [$willResetDemoData ? 'accepted' : 'nullable'],
        ];
    }

    private function normalizePhones(Request $request): void
    {
        $staff = collect($request->input('staff', []))
            ->filter(fn ($member) => filled($member['name'] ?? null))
            ->map(function ($member) {
                $member['name'] = trim((string) ($member['name'] ?? ''));
                $member['phone'] = filled($member['phone'] ?? null)
                    ? PhoneNumber::normalize($member['phone'])
                    : null;

                return $member;
            })
            ->values()
            ->all();

        $request->merge([
            'business_owner_phone' => $request->filled('business_owner_phone')
                ? PhoneNumber::normalize($request->input('business_owner_phone'))
                : null,
            'admin_phone' => $request->filled('admin_phone')
                ? PhoneNumber::normalize($request->input('admin_phone'))
                : null,
            'staff' => $staff,
        ]);
    }

    private function writeCleanSettings(array $data, Request $request): void
    {
        Setting::put('site_name', $data['restaurant_name'], 'general');
        $this->putOptionalSetting('legal_name', $data['legal_name'] ?? null, 'general');
        $this->putOptionalSetting('tax_number', $data['tax_number'] ?? null, 'general');
        $this->putOptionalSetting('receipt_footer', $data['receipt_footer'] ?? null, 'billing');

        $currency = [
            'sales_currency' => 'ILS',
            'accounting_base_currency' => 'ILS',
            'currency_symbol' => '₪',
            'accounting_currency_symbol' => '₪',
            'sales_to_accounting_rate' => 1.0,
        ];
        Setting::put('currency_symbol', '₪', 'billing');
        Setting::put('sales_currency', 'ILS', 'billing');
        Setting::put('accounting_base_currency', 'ILS', 'accounting');
        Setting::put('accounting_currency_symbol', '₪', 'accounting');
        Setting::put('sales_to_accounting_rate', 1.0, 'accounting', 'float');
        Setting::put('fiscal_year_start_month', 1, 'accounting', 'int');
        Setting::put('fiscal_year_start_day', 1, 'accounting', 'int');
        $this->syncCurrencies($currency);

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('tax_rate', 0, 'billing', 'float');
        Setting::put('customer_tax_display', 'exclusive', 'billing');
        Setting::put('service_enabled', false, 'billing', 'bool');
        Setting::put('service_rate', 0, 'billing', 'float');
        CustomerSalesTaxRate::updateOrCreate(
            ['effective_from' => now()->toDateString()],
            ['enabled' => false, 'rate' => 0, 'created_by' => null],
        );

        $theme = config('restaurant.theme', []);
        foreach ([
            'theme_primary' => $theme['primary'] ?? '#1f6b50',
            'theme_dark' => $theme['dark'] ?? '#123f31',
            'theme_header' => $theme['header'] ?? '#ffffff',
            'theme_accent' => $theme['accent'] ?? '#b97818',
            'theme_menu' => $theme['menu'] ?? '#ffffff',
            'theme_header_style' => $theme['header_style'] ?? 'color',
            'theme_menu_style' => $theme['menu_style'] ?? 'brand',
        ] as $key => $value) {
            Setting::put($key, $value, 'theme');
        }

        foreach (['brand_logo', 'brand_favicon'] as $field) {
            if ($request->hasFile($field)) {
                Setting::put($field, $this->storeBrandAsset($request, $field), 'brand');
            }
        }

        Setting::put('strict_stock', true, 'inventory', 'bool');
        Setting::put('inventory_deduction_stage', 'preparing', 'inventory');
        Setting::put('customer_cancel_window_seconds', 120, 'customer', 'int');
        Setting::put('session_ttl_minutes', 240, 'customer', 'int');
        Setting::put('sync_enabled', false, 'sync', 'bool');
        Setting::put('sync_role', 'standalone', 'sync');
    }

    private function visibleDefaults(?User $user, ?Branch $branch, bool $isDemoTrial): array
    {
        return [
            'restaurant_name' => Setting::get('site_name', config('restaurant.name', 'مطعمي')),
            'legal_name' => Setting::get('legal_name', ''),
            'tax_number' => Setting::get('tax_number', ''),
            'commercial_registration_number' => $branch?->legalProfile?->commercial_registration_number ?? '',
            'municipal_license_number' => $branch?->legalProfile?->municipal_license_number ?? '',
            'receipt_footer' => Setting::get('receipt_footer', 'شكراً لزيارتكم'),
            'branch_code' => $branch?->code ?? 'main',
            'branch_name' => $branch?->name ?? 'الفرع الرئيسي',
            'branch_phone' => $branch?->phone ?? '',
            'branch_city' => $branch?->city ?? '',
            'branch_address' => $branch?->address ?? '',
            'business_owner_type' => 'person',
            'business_owner_name' => $branch?->owners()->orderByDesc('branch_ownerships.is_primary')->value('business_owners.name') ?? '',
            'business_owner_national_id' => '',
            'business_owner_phone' => '',
            'business_owner_percentage' => 100,
            // Demo identities are disposable. Never let their name, email or
            // mobile silently become the owner's real production identity.
            'admin_name' => $isDemoTrial ? '' : ($user?->name ?? ''),
            'admin_username' => $user?->username ?? 'admin',
            'admin_email' => $isDemoTrial ? '' : ($user?->email ?? ''),
            'admin_phone' => $isDemoTrial ? '' : ($user?->phone ?? ''),
        ];
    }

    private function handoverSummary(?Branch $branch): array
    {
        $branchId = $branch?->id;
        $itemIds = $branchId
            ? DB::table('menu_items')->where('branch_id', $branchId)->pluck('id')
            : collect();

        return [
            'branchName' => $branch?->name,
            'categories' => $branchId ? DB::table('categories')->where('branch_id', $branchId)->count() : 0,
            'items' => $itemIds->count(),
            'ingredients' => DB::table('ingredients')->count(),
            'recipes' => $itemIds->isEmpty() ? 0 : DB::table('recipe_items')->whereIn('menu_item_id', $itemIds)->count(),
            'orders' => DB::table('orders')->count(),
            'invoices' => DB::table('invoices')->count(),
            'customers' => DB::table('customers')->count(),
            'staff' => DB::table('users')->count(),
            'branches' => DB::table('branches')->count(),
        ];
    }

    /** @return array<int,array{value:string,label:string,description:string,icon:string}> */
    private function teamRoleCards(): array
    {
        return collect(self::TEAM_ROLES)->map(function (string $value) {
            $role = UserRole::from($value);

            return [
                'value' => $value,
                'label' => $role->label(),
                'description' => match ($role) {
                    UserRole::Manager => 'يتابع الفرع والموظفين والتشغيل.',
                    UserRole::Accountant => 'يدير الدفتر والقيود والتقارير.',
                    UserRole::Waiter => 'يتابع الطاولات والطلبات والتسليم.',
                    UserRole::Chef => 'يستلم طلبات المطبخ ويجهزها.',
                    UserRole::Bartender => 'يستلم طلبات البار ويجهزها.',
                    UserRole::Cashier => 'يحصّل الفواتير ويسجل الدفعات.',
                    default => '',
                },
                'icon' => match ($role) {
                    UserRole::Manager => 'bi-person-badge',
                    UserRole::Accountant => 'bi-calculator',
                    UserRole::Waiter => 'bi-person-check',
                    UserRole::Chef => 'bi-egg-fried',
                    UserRole::Bartender => 'bi-cup-straw',
                    UserRole::Cashier => 'bi-receipt-cutoff',
                    default => 'bi-person',
                },
            ];
        })->all();
    }

    private function ensureBranchSkeleton(Branch $branch): void
    {
        $location = StorageLocation::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->orderByDesc('is_default')
            ->first();

        if (! $location) {
            $location = StorageLocation::create([
                'branch_id' => $branch->id,
                'code' => 'main-'.$branch->id,
                'name' => 'المخزن الرئيسي',
                'icon' => 'bi-box-seam',
                'is_default' => true,
                'active' => true,
                'display_order' => 0,
            ]);
        }

        foreach ([
            ['code' => 'kitchen', 'name' => 'المطبخ', 'icon' => 'bi-egg-fried', 'order' => 1],
            ['code' => 'bar', 'name' => 'البار', 'icon' => 'bi-cup-straw', 'order' => 2],
        ] as $station) {
            Station::withoutGlobalScopes()->firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $station['code']],
                [
                    'name' => $station['name'],
                    'color' => '#1f6b50',
                    'icon' => $station['icon'],
                    'storage_location_id' => $location->id,
                    'display_order' => $station['order'],
                    'active' => true,
                ],
            );
        }
    }

    private function syncCurrencies(array $data): void
    {
        Currency::query()->update(['is_base' => false]);
        Currency::updateOrCreate(
            ['code' => $data['accounting_base_currency']],
            [
                'name' => 'Israeli Shekel',
                'symbol' => $data['accounting_currency_symbol'],
                'rate_to_base' => 1,
                'is_base' => true,
                'is_active' => true,
                'display_order' => 1,
                'rate_updated_at' => now(),
            ],
        );

        // USD stays available for supplier invoices, but the fresh ledger is
        // ILS-based until the accountant records the first real exchange rate.
        Currency::updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'symbol' => '$',
                'rate_to_base' => (float) (Currency::where('code', 'USD')->value('rate_to_base') ?: 3.65),
                'is_base' => false,
                'is_active' => true,
                'display_order' => 2,
                'rate_updated_at' => now(),
            ],
        );

        config(['restaurant.currency' => 'ILS', 'restaurant.currency_symbol' => '₪']);
    }

    private function createInitialFiscalYear(Branch $branch): void
    {
        $startsOn = Carbon::now()->startOfYear();
        $endsOn = $startsOn->copy()->endOfYear();

        FiscalYear::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
            ],
            ['name' => 'FY '.$startsOn->year, 'status' => 'open'],
        );
    }

    private function completedRedirect()
    {
        return Auth::check()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('login');
    }

    private function setupBranch(): ?Branch
    {
        $activeBranchId = session('active_branch_id');

        return Branch::withoutGlobalScopes()
            ->when($activeBranchId, fn ($query) => $query->where('id', $activeBranchId))
            ->first()
            ?? Branch::withoutGlobalScopes()->where('is_active', true)->orderBy('display_order')->orderBy('id')->first()
            ?? Branch::withoutGlobalScopes()->orderBy('id')->first();
    }

    private function putOptionalSetting(string $key, mixed $value, string $group): void
    {
        if ($value === null || $value === '') {
            Setting::query()->where('key', $key)->delete();
            Cache::forget('setting.'.$key);

            return;
        }

        Setting::put($key, $value, $group);
    }

    private function clearUploadedBrandAssets(): void
    {
        foreach (['brand_logo', 'brand_favicon'] as $key) {
            $path = trim((string) Setting::get($key, ''));
            if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:')) {
                continue;
            }

            $normalized = ltrim($path, '/');
            Storage::disk('public')->delete(str_starts_with($normalized, 'storage/')
                ? substr($normalized, strlen('storage/'))
                : $normalized);
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
