@extends('layouts.admin')
@section('title','الإعدادات')

@push('styles')
<style>
/* Minimal helpers — everything else uses Dashtic's native classes */
.color-field { display:flex; align-items:center; gap:10px; padding:10px 12px; background:rgba(var(--accent-rgb),.04); border-radius:10px; border:1px solid rgba(var(--accent-rgb),.18); }
.color-field input[type=color] { width:48px; height:38px; padding:2px; border:0; border-radius:8px; cursor:pointer; }
.color-field input[type=text]  { flex:1; font-family:monospace; font-weight:600; background:#fff; border:1px solid rgba(var(--primary-rgb),.1); border-radius:6px; padding:6px 10px; }
.preset-palette { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.preset { padding:6px 10px; border-radius:8px; border:1px solid rgba(var(--primary-rgb),.12); background:#fff; cursor:pointer; display:inline-flex; align-items:center; gap:6px; font-size:.8rem; font-weight:600; transition: border-color .12s; }
.preset:hover { border-color: var(--accent); }
.preset-swatch { width:14px; height:14px; border-radius:3px; display:inline-block; }
.brand-preview { border-radius:12px; overflow:hidden; border:1px solid rgba(var(--primary-rgb),.1); }
.brand-preview-header { padding:1rem; display:flex; justify-content:space-between; align-items:center; color:#fff; }
.brand-preview-body { padding:1rem; background:#fff; }
.settings-tabs .nav-link { font-weight:700; }
.settings-tabs .nav-link.active { color: rgb(var(--primary-rgb)); border-bottom-color: var(--accent); }
</style>
@endpush

@section('content')
<x-admin.breadcrumb title="الإعدادات" icon="bi-gear"
    subtitle="إعدادات المطعم، الضرائب، المظهر، والعملات" />

<div class="card">
    <div class="card-body">

        <ul class="nav nav-tabs settings-tabs mb-3" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tab-general">
                    <i class="bi bi-gear"></i> عام
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-theme">
                    <i class="bi bi-palette"></i> الألوان والهوية
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-currencies">
                    <i class="bi bi-currency-exchange"></i> العملات
                    <span class="badge bg-primary-transparent ms-1">{{ $currencies->count() }}</span>
                </a>
            </li>
        </ul>

        <div class="tab-content">

            {{-- ═══════════════════════════════════════════════════════
                 TABS 1 & 2: General + Theme (share one form + one save)
                 ═══════════════════════════════════════════════════════ --}}
            <form method="POST" action="{{ route('admin.settings.update') }}" id="settingsForm">
                @csrf @method('PUT')

                {{-- ─── General tab ─── --}}
                <div class="tab-pane fade show active" id="tab-general">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">اسم المطعم</label>
                            <input name="site_name"
                                value="{{ \App\Models\Setting::get('site_name', config('restaurant.name')) }}"
                                class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">رمز العملة (للعرض)</label>
                            <input name="currency_symbol"
                                value="{{ \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol')) }}"
                                class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">نسبة الضريبة %</label>
                            <input type="number" step="0.01" name="tax_rate"
                                value="{{ \App\Models\Setting::get('tax_rate', config('restaurant.tax.rate')) }}"
                                class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">نسبة الخدمة %</label>
                            <input type="number" step="0.01" name="service_rate"
                                value="{{ \App\Models\Setting::get('service_rate', config('restaurant.service_charge.rate')) }}"
                                class="form-control" required>
                        </div>

                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="hidden" name="tax_enabled" value="0">
                                <input type="checkbox" id="cbTax" name="tax_enabled" value="1" class="form-check-input"
                                    @checked(\App\Models\Setting::get('tax_enabled', config('restaurant.tax.enabled')))>
                                <label class="form-check-label" for="cbTax">تفعيل الضريبة</label>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="hidden" name="service_enabled" value="0">
                                <input type="checkbox" id="cbSvc" name="service_enabled" value="1" class="form-check-input"
                                    @checked(\App\Models\Setting::get('service_enabled', config('restaurant.service_charge.enabled')))>
                                <label class="form-check-label" for="cbSvc">تفعيل الخدمة</label>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="hidden" name="auto_approve" value="0">
                                <input type="checkbox" id="cbAuto" name="auto_approve" value="1" class="form-check-input"
                                    @checked(\App\Models\Setting::get('auto_approve', config('restaurant.order.auto_approve')))>
                                <label class="form-check-label" for="cbAuto">اعتماد تلقائي للطلبات</label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ─── Theme tab ─── --}}
                <div class="tab-pane fade" id="tab-theme" x-data="themeTab()">
                    <div class="alert bg-primary-transparent border-0 py-2 mb-3">
                        <i class="bi bi-info-circle"></i>
                        الألوان الافتراضية مطابقة للهوية الحالية (أخضر غابي + ذهبي زيتوني).
                        يمكنك تعديلها وسيتم تطبيقها على لوحة التحكم فور الحفظ.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">أطقم جاهزة:</label>
                        <div class="preset-palette">
                            <button type="button" class="preset" @click="applyPreset('forest')">
                                <span class="preset-swatch" style="background:#1f4733"></span>
                                <span class="preset-swatch" style="background:#b8872a"></span>
                                غابي + ذهبي (الافتراضي)
                            </button>
                            <button type="button" class="preset" @click="applyPreset('emerald')">
                                <span class="preset-swatch" style="background:#059669"></span>
                                <span class="preset-swatch" style="background:#eab308"></span>
                                زمردي + أصفر
                            </button>
                            <button type="button" class="preset" @click="applyPreset('burgundy')">
                                <span class="preset-swatch" style="background:#7c2d12"></span>
                                <span class="preset-swatch" style="background:#f59e0b"></span>
                                عنابي + كهرماني
                            </button>
                            <button type="button" class="preset" @click="applyPreset('navy')">
                                <span class="preset-swatch" style="background:#1e3a8a"></span>
                                <span class="preset-swatch" style="background:#f59e0b"></span>
                                كحلي + كهرماني
                            </button>
                            <button type="button" class="preset" @click="applyPreset('rose')">
                                <span class="preset-swatch" style="background:#be185d"></span>
                                <span class="preset-swatch" style="background:#fbbf24"></span>
                                وردي + ذهبي
                            </button>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold">اللون الأساسي (Primary)</label>
                            <div class="color-field">
                                <input type="color" x-model="colors.primary" @input="sync('primary')">
                                <input type="text"  x-model="colors.primary" @change="sync('primary')" maxlength="7">
                            </div>
                            <small class="text-muted">يستخدم للأزرار، الروابط، العناصر النشطة</small>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">لون التمييز (Accent)</label>
                            <div class="color-field">
                                <input type="color" x-model="colors.accent" @input="sync('accent')">
                                <input type="text"  x-model="colors.accent" @change="sync('accent')" maxlength="7">
                            </div>
                            <small class="text-muted">ذهبي للشارات، الإشعارات، الخطوط المميزة</small>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">الـ Header</label>
                            <div class="color-field">
                                <input type="color" x-model="colors.header" @input="sync('header')">
                                <input type="text"  x-model="colors.header" @change="sync('header')" maxlength="7">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">اللون الغامق (Dark)</label>
                            <div class="color-field">
                                <input type="color" x-model="colors.dark" @input="sync('dark')">
                                <input type="text"  x-model="colors.dark" @change="sync('dark')" maxlength="7">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold">خلفية السايدبار (Menu)</label>
                            <div class="color-field">
                                <input type="color" x-model="colors.menu" @input="sync('menu')">
                                <input type="text"  x-model="colors.menu" @change="sync('menu')" maxlength="7">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold">نمط الـ Header</label>
                            <select name="theme_header_style" x-model="headerStyle" class="form-select">
                                <option value="light">فاتح</option>
                                <option value="dark">غامق</option>
                                <option value="color">ملوّن (الهوية)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold">نمط الـ Sidebar</label>
                            <select name="theme_menu_style" x-model="menuStyle" class="form-select">
                                <option value="brand">بلون الهوية (أخضر)</option>
                                <option value="light">فاتح / كريمي</option>
                                <option value="dark">غامق</option>
                            </select>
                        </div>
                    </div>

                    <input type="hidden" name="theme_primary" :value="colors.primary">
                    <input type="hidden" name="theme_dark"    :value="colors.dark">
                    <input type="hidden" name="theme_header"  :value="colors.header">
                    <input type="hidden" name="theme_accent"  :value="colors.accent">
                    <input type="hidden" name="theme_menu"    :value="colors.menu">

                    <div class="mt-4">
                        <label class="fw-bold mb-2">معاينة مباشرة:</label>
                        <div class="brand-preview">
                            <div class="brand-preview-header"
                                :style="`background: linear-gradient(135deg, ${colors.primary} 0%, ${colors.dark} 100%); border-bottom: 2px solid ${colors.accent}`">
                                <strong>🌳 {{ \App\Models\Setting::get('site_name', config('restaurant.name')) }}</strong>
                                <span style="background:#fff; color:#111; padding:4px 12px; border-radius:10px; font-size:.85rem; font-weight:700;">لوحة التحكم</span>
                            </div>
                            <div class="brand-preview-body">
                                <button type="button" class="btn btn-sm" :style="`background: ${colors.primary}; color:#fff; border:0;`">زر أساسي</button>
                                <button type="button" class="btn btn-sm ms-2" :style="`background: ${colors.accent}; color: ${colors.dark}; border:0; font-weight:700;`">مميز</button>
                                <span class="ms-2 badge" :style="`background: ${colors.accent}; color: ${colors.dark}`">متاح اليوم</span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                            onclick="document.getElementById('resetThemeForm').submit()">
                            <i class="bi bi-arrow-counterclockwise"></i> استعادة الألوان الافتراضية
                        </button>
                    </div>
                </div>

                <div class="mt-4 text-start" id="settingsSaveRow">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> حفظ الإعدادات العامة والهوية
                    </button>
                </div>
            </form>
            {{-- End of main settings form (general + theme). --}}


            {{-- ═══════════════════════════════════════════════════════
                 TAB 3: Currencies (has its own forms — kept OUTSIDE main form)
                 ═══════════════════════════════════════════════════════ --}}
            <div class="tab-pane fade" id="tab-currencies">

                <div class="alert bg-primary-transparent border-0 mb-3">
                    <i class="bi bi-info-circle-fill"></i>
                    <strong>كيف يعمل النظام:</strong>
                    العملة الأساسية
                    (<strong>{{ $currencies->firstWhere('is_base')?->code ?? '—' }}</strong>)
                    هي العملة المسجَّلة في كل الفواتير. العملات الأخرى تُحوَّل للعرض فقط —
                    لتسهيل القراءة على السيّاح. لا تؤثر على المحاسبة.
                </div>

                {{-- Add new currency (collapsible inline card) --}}
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-coin text-accent"></i>
                        العملات المتاحة
                        <span class="badge bg-primary-transparent">{{ $currencies->count() }}</span>
                    </h6>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addCurrencyCollapse">
                        <i class="bi bi-plus-lg"></i> إضافة عملة
                    </button>
                </div>

                <div class="collapse mb-3" id="addCurrencyCollapse">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.currencies.store') }}">
                                @csrf
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label small fw-bold">الرمز *</label>
                                        <input type="text" name="code" class="form-control" maxlength="3" placeholder="USD" required dir="ltr" style="text-transform:uppercase">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold">الاسم *</label>
                                        <input type="text" name="name" class="form-control" placeholder="دولار أمريكي" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small fw-bold">الرمز المالي *</label>
                                        <input type="text" name="symbol" class="form-control" placeholder="$" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold">سعر الصرف للأساسية *</label>
                                        <input type="number" step="0.000001" min="0.000001" name="rate_to_base" class="form-control" required placeholder="0.71">
                                    </div>
                                    <div class="col-md-2 d-flex gap-1">
                                        <button class="btn btn-primary flex-fill">
                                            <i class="bi bi-check"></i> إضافة
                                        </button>
                                        <button type="button" class="btn btn-light" data-bs-toggle="collapse" data-bs-target="#addCurrencyCollapse">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <small class="text-muted">سعر الصرف = 1 وحدة من هذه العملة كم تساوي من العملة الأساسية. مثال: 1 USD = 0.71 JOD</small>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Rates table --}}
                <form method="POST" action="{{ route('admin.currencies.update-rates') }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>الرمز</th>
                                    <th>الاسم</th>
                                    <th>الرمز المالي</th>
                                    <th>سعر الصرف للأساسية</th>
                                    <th>مثال</th>
                                    <th>نشطة</th>
                                    <th>آخر تحديث</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $base = $currencies->firstWhere('is_base'); @endphp
                                @forelse($currencies as $c)
                                    <tr>
                                        <td>
                                            <strong>{{ $c->code }}</strong>
                                            @if($c->is_base)
                                                <span class="badge bg-primary-transparent ms-1">أساسية</span>
                                            @endif
                                        </td>
                                        <td>{{ $c->name }}</td>
                                        <td><code>{{ $c->symbol }}</code></td>
                                        <td>
                                            @if($c->is_base)
                                                <span class="fw-bold text-muted">1.000000</span>
                                            @else
                                                <div class="input-group input-group-sm" style="max-width:200px">
                                                    <input type="number" step="0.000001" min="0.000001"
                                                        name="rates[{{ $c->id }}]" value="{{ $c->rate_to_base }}"
                                                        class="form-control">
                                                    <span class="input-group-text small">= {{ $base?->code }}</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="small text-muted">
                                            @if(!$c->is_base && $base)
                                                1 {{ $c->code }} = {{ number_format($c->rate_to_base, 4) }} {{ $base->code }}<br>
                                                1 {{ $base->code }} ≈ {{ number_format(1 / max((float)$c->rate_to_base, 0.0001), 4) }} {{ $c->code }}
                                            @endif
                                        </td>
                                        <td>
                                            @if($c->is_base)
                                                <i class="bi bi-check-circle-fill text-success" title="العملة الأساسية دائماً فعّالة"></i>
                                            @else
                                                <input type="hidden" name="active[{{ $c->id }}]" value="0">
                                                <div class="form-check form-switch">
                                                    <input type="checkbox" name="active[{{ $c->id }}]" value="1"
                                                        class="form-check-input" @checked($c->is_active)>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="small text-muted">{{ $c->rate_updated_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="text-end">
                                            @if(!$c->is_base)
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="if(confirm('حذف هذه العملة؟')) document.getElementById('delCurr{{ $c->id }}').submit()">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8">
                                        <x-admin.empty-state icon="bi-currency-exchange" message="لا توجد عملات بعد" compact />
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 text-start">
                        <button class="btn btn-primary">
                            <i class="bi bi-save"></i> حفظ أسعار الصرف
                        </button>
                    </div>
                </form>

                {{-- Delete forms (one per non-base currency, hidden, triggered by button above) --}}
                @foreach($currencies as $c)
                    @if(!$c->is_base)
                        <form id="delCurr{{ $c->id }}" method="POST" action="{{ route('admin.currencies.destroy', $c) }}" class="d-none">
                            @csrf @method('DELETE')
                        </form>
                    @endif
                @endforeach
            </div>

        </div>
        {{-- End tab-content --}}

        {{-- Reset-theme form (outside main form) --}}
        <form id="resetThemeForm" action="{{ route('admin.settings.reset-theme') }}" method="POST" class="d-none">@csrf</form>

    </div>
</div>

{{-- When settings tab isn't "currencies", show the Save button row. Hide it on currencies tab. --}}
<script>
    (function() {
        const saveRow = document.getElementById('settingsSaveRow');
        const tabs = document.querySelectorAll('.settings-tabs .nav-link');
        function update() {
            const isCurrencies = document.querySelector('#tab-currencies.active, #tab-currencies.show.active');
            if (saveRow) saveRow.style.display = isCurrencies ? 'none' : '';
        }
        tabs.forEach(t => t.addEventListener('shown.bs.tab', update));
        update();

        // If URL has a #tab-... hash, activate that tab on load
        if (location.hash && location.hash.startsWith('#tab-')) {
            const trigger = document.querySelector(`[data-bs-target="${location.hash}"], [href="${location.hash}"]`);
            if (trigger && window.bootstrap) new bootstrap.Tab(trigger).show();
        }
    })();
</script>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<script>
function themeTab() {
    return {
        colors: {
            primary: @json(\App\Models\Setting::get('theme_primary', config('restaurant.theme.primary'))),
            dark:    @json(\App\Models\Setting::get('theme_dark',    config('restaurant.theme.dark'))),
            header:  @json(\App\Models\Setting::get('theme_header',  config('restaurant.theme.header'))),
            accent:  @json(\App\Models\Setting::get('theme_accent',  config('restaurant.theme.accent'))),
            menu:    @json(\App\Models\Setting::get('theme_menu',    config('restaurant.theme.menu'))),
        },
        headerStyle: @json(\App\Models\Setting::get('theme_header_style', config('restaurant.theme.header_style'))),
        menuStyle:   @json(\App\Models\Setting::get('theme_menu_style',   config('restaurant.theme.menu_style'))),
        sync(key) {
            if (!this.colors[key].startsWith('#')) this.colors[key] = '#' + this.colors[key];
        },
        presets: {
            forest:   { primary: '#1f4733', dark: '#122d1e', header: '#1f4733', accent: '#b8872a', menu: '#faf5eb' },
            emerald:  { primary: '#059669', dark: '#064e3b', header: '#059669', accent: '#eab308', menu: '#f0fdf4' },
            burgundy: { primary: '#7c2d12', dark: '#431407', header: '#7c2d12', accent: '#f59e0b', menu: '#fff7ed' },
            navy:     { primary: '#1e3a8a', dark: '#0f172a', header: '#1e3a8a', accent: '#f59e0b', menu: '#f1f5f9' },
            rose:     { primary: '#be185d', dark: '#831843', header: '#be185d', accent: '#fbbf24', menu: '#fff1f2' },
        },
        applyPreset(name) {
            const p = this.presets[name];
            if (!p) return;
            this.colors = { ...p };
        },
    };
}
</script>
@endpush
