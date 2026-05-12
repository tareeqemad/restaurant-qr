@extends('layouts.admin')
@section('title', 'الإعدادات')

@php
    use App\Helpers\Brand;
    use App\Models\Setting;

    $theme = config('restaurant.theme', []);
    $read = fn (string $key, mixed $default = null) => old($key, Setting::get($key, $default));
    $checked = fn (string $key, mixed $default = false) => (bool) Setting::get($key, $default);
    $canEditSettings = auth()->user()->hasAnyRole(['super_admin', 'admin']);
    $baseCurrency = $currencies->firstWhere('is_base');
@endphp

@push('styles')
<style>
    .settings-shell .nav-link {
        border: 1px solid transparent;
        color: #53645f;
        font-weight: 800;
        padding: .7rem 1rem;
        border-radius: 10px;
    }
    .settings-shell .nav-link.active {
        color: rgb(var(--primary-rgb));
        background: rgba(var(--primary-rgb), .08);
        border-color: rgba(var(--primary-rgb), .14);
    }
    .settings-card {
        border: 1px solid rgba(var(--primary-rgb), .12);
        border-radius: 12px;
        background: #fff;
    }
    .settings-shell .tab-pane {
        display: none;
    }
    .settings-shell .tab-pane.active {
        display: block;
    }
    .settings-card .card-header {
        background: linear-gradient(180deg, rgba(var(--primary-rgb), .04), rgba(var(--primary-rgb), .015));
        border-bottom: 1px solid rgba(var(--primary-rgb), .08);
    }
    .setting-hint {
        color: #6b7a76;
        font-size: .82rem;
        line-height: 1.6;
    }
    .brand-asset-preview {
        min-height: 126px;
        border: 1px dashed rgba(var(--primary-rgb), .18);
        border-radius: 12px;
        background: #f8faf8;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .brand-asset-preview img {
        max-width: 210px;
        max-height: 90px;
        object-fit: contain;
    }
    .theme-preset {
        border: 1px solid rgba(var(--primary-rgb), .13);
        background: #fff;
        border-radius: 10px;
        padding: .55rem .75rem;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        gap: .45rem;
    }
    .theme-preset:hover { border-color: rgb(var(--accent-rgb)); }
    .swatch {
        width: 16px;
        height: 16px;
        border-radius: 4px;
        display: inline-block;
        box-shadow: inset 0 0 0 1px rgba(0,0,0,.1);
    }
    .color-input {
        height: 44px;
        padding: .25rem;
    }
</style>
@endpush

@section('content')
<x-admin.breadcrumb title="الإعدادات" icon="bi-gear"
    subtitle="هوية المطعم، الفوترة، تجربة QR، المخزون، الألوان والعملات" />

@if($errors->any())
    <div class="alert alert-danger">
        <strong>راجع الحقول التالية:</strong>
        <ul class="mb-0 mt-2">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@unless($canEditSettings)
    <div class="alert alert-warning">
        لديك صلاحية مشاهدة الإعدادات فقط. التعديل متاح للمدير العام أو مدير النظام.
    </div>
@endunless

<div class="card settings-shell">
    <div class="card-body">
        <ul class="nav gap-2 mb-4" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-general"><i class="bi bi-building"></i> عام</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-billing"><i class="bi bi-receipt"></i> الفوترة</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-operations"><i class="bi bi-sliders"></i> التشغيل</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-discounts"><i class="bi bi-percent"></i> الخصومات</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-brand"><i class="bi bi-image"></i> الشعار</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-theme"><i class="bi bi-palette"></i> الهوية</a></li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#tab-currencies">
                    <i class="bi bi-currency-exchange"></i> العملات
                    <span class="badge bg-primary-transparent ms-1">{{ $currencies->count() }}</span>
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <form method="POST" action="{{ route('admin.settings.update') }}" id="settingsForm">
                @csrf
                @method('PUT')

                <div class="tab-pane fade show active" id="tab-general">
                    <div class="settings-card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-building text-accent"></i> بيانات المطعم الأساسية</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">اسم المطعم في النظام <span class="text-danger">*</span></label>
                                    <input name="site_name" class="form-control" required maxlength="120"
                                        value="{{ $read('site_name', config('restaurant.name')) }}">
                                    <div class="setting-hint mt-1">يظهر في الهيدر، صفحة الدخول، المنيو، والفواتير.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">الاسم القانوني / التجاري</label>
                                    <input name="legal_name" class="form-control" maxlength="160"
                                        value="{{ $read('legal_name') }}" placeholder="مثلاً: شركة Relax Food Services">
                                    <div class="setting-hint mt-1">اختياري، لكنه مهم لو بدك يظهر على الفواتير الرسمية.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">الرقم الضريبي</label>
                                    <input name="tax_number" class="form-control" maxlength="80"
                                        value="{{ $read('tax_number') }}" placeholder="اختياري">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">رمز العملة الأساسي <span class="text-danger">*</span></label>
                                    <input name="currency_symbol" class="form-control" required maxlength="10"
                                        value="{{ $read('currency_symbol', config('restaurant.currency_symbol')) }}">
                                    <div class="setting-hint mt-1">هذا الرمز يستخدم في الفواتير والتقارير. جدول العملات للعرض المتعدد فقط.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">العملة الأساسية</label>
                                    <input class="form-control" value="{{ $baseCurrency?->code ?? config('restaurant.currency') }}" disabled>
                                    <div class="setting-hint mt-1">تتغير من جدول العملات وليس من هذا الحقل.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-billing">
                    <div class="settings-card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-receipt-cutoff text-accent"></i> الضريبة، الخدمة، والفاتورة</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">نسبة الضريبة %</label>
                                    <input type="number" step="0.01" min="0" max="100" name="tax_rate" class="form-control"
                                        value="{{ $read('tax_rate', config('restaurant.tax.rate')) }}" required>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="tax_enabled" value="0">
                                        <input type="checkbox" id="tax_enabled" name="tax_enabled" value="1" class="form-check-input"
                                            @checked($checked('tax_enabled', config('restaurant.tax.enabled')))>
                                        <label for="tax_enabled" class="form-check-label fw-bold">تفعيل الضريبة</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">عرض إجمالي QR للزبون</label>
                                    @php $taxDisplay = $read('customer_tax_display', 'exclusive'); @endphp
                                    <select name="customer_tax_display" class="form-select" required>
                                        <option value="exclusive" @selected($taxDisplay === 'exclusive')>قبل الضريبة</option>
                                        <option value="inclusive" @selected($taxDisplay === 'inclusive')>شامل الضريبة</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">نسبة الخدمة %</label>
                                    <input type="number" step="0.01" min="0" max="100" name="service_rate" class="form-control"
                                        value="{{ $read('service_rate', config('restaurant.service_charge.rate')) }}" required>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="service_enabled" value="0">
                                        <input type="checkbox" id="service_enabled" name="service_enabled" value="1" class="form-check-input"
                                            @checked($checked('service_enabled', config('restaurant.service_charge.enabled')))>
                                        <label for="service_enabled" class="form-check-label fw-bold">تفعيل الخدمة للصالة</label>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label">رسالة أسفل الفاتورة</label>
                                    <textarea name="receipt_footer" class="form-control" rows="2" maxlength="500"
                                        placeholder="مثلاً: شكراً لزيارتكم، نتمنى لكم يوماً سعيداً">{{ $read('receipt_footer', 'شكراً لزيارتكم') }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-operations">
                    <div class="settings-card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-sliders text-accent"></i> إعدادات التشغيل اليومية</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">مدة جلسة QR بالدقائق</label>
                                    <input type="number" min="30" max="1440" step="1" name="session_ttl_minutes" class="form-control"
                                        value="{{ $read('session_ttl_minutes', config('restaurant.order.session_ttl_minutes', 240)) }}" required>
                                    <div class="setting-hint mt-1">بعدها يحتاج الزبون يمسح QR من جديد، إلا إذا الجلسة ما زالت فعالة.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">مهلة إلغاء الزبون للطلب بالثواني</label>
                                    <input type="number" min="0" max="900" step="1" name="customer_cancel_window_seconds" class="form-control"
                                        value="{{ $read('customer_cancel_window_seconds', config('restaurant.order.customer_cancel_window_seconds', 120)) }}" required>
                                    <div class="setting-hint mt-1">0 يعني لا يسمح للزبون بالإلغاء من شاشة QR.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">هامش وقت التحضير %</label>
                                    <div class="input-group">
                                        <input type="number" min="0" max="200" step="1"
                                               name="prep_time_buffer_pct" class="form-control"
                                               value="{{ $read('prep_time_buffer_pct', 20) }}">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <div class="setting-hint mt-1">
                                        نسبة إضافية فوق وقت تحضير أطول صنف في الطلب لحساب الوقت المتوقع
                                        للزبون والمطبخ. مثال: برغر 12 د + هامش 20% = 14.4 د كـ ETA.
                                        ارفعه لو المطبخ بطيء أو تحت ضغط.
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="customer_currency_switcher" value="0">
                                        <input type="checkbox" id="customer_currency_switcher" name="customer_currency_switcher" value="1" class="form-check-input"
                                            @checked($checked('customer_currency_switcher', config('restaurant.customer.currency_switcher', false)))>
                                        <label for="customer_currency_switcher" class="form-check-label fw-bold">إظهار مبدّل العملات للزبون</label>
                                        <div class="setting-hint">يفضل إيقافه لو المطعم يتعامل بعملة واحدة.</div>
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input type="hidden" name="strict_stock" value="0">
                                        <input type="checkbox" id="strict_stock" name="strict_stock" value="1" class="form-check-input"
                                            @checked($checked('strict_stock', config('restaurant.inventory.strict_stock', true)))>
                                        <label for="strict_stock" class="form-check-label fw-bold">منع بيع مخزون غير متاح</label>
                                        <div class="setting-hint">مهم جداً للمطبخ والبار. إيقافه مناسب للديمو فقط.</div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="alert bg-primary-transparent border-0 mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        خيار الاعتماد التلقائي للطلبات غير معروض هنا لأنه غير مناسب حالياً مع مسار الجرسون.
                                        الطلبات تبقى بانتظار اعتماد الجرسون حتى لا يذهب شيء للمطبخ/البار بالخطأ.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-discounts">
                    <div class="settings-card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-percent text-accent"></i> سقوف الخصم لكل دور</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert bg-primary-transparent border-0 mb-3">
                                <i class="bi bi-info-circle"></i>
                                هذه السقوف تحدد الحد الأقصى للخصم الذي يقدر الموظف يطبقه على فاتورة من شاشة الكاشير.
                                <strong>السوبر أدمن والشريك بدون سقف</strong>. لو الموظف حاول خصم أكبر من سقفه، النظام يرفض ويطلب موافقة مدير.
                                <br>
                                <small class="text-muted">
                                    <i class="bi bi-tags"></i>
                                    لإدارة <strong>تصنيفات أسباب الخصم</strong> (عميل دائم، شكوى، …) من
                                    <a href="{{ route('admin.lookups.index') }}#group=discount_categories">شاشة الثوابت</a>.
                                </small>
                            </div>

                            @php
                                $caps = config('restaurant.discounts.caps', []);
                                $currency = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', '₪'));
                                $roleLabels = ['cashier' => 'الكاشير', 'waiter' => 'الجرسون', 'manager' => 'مدير الفرع'];
                            @endphp

                            <div class="row g-3">
                                @foreach($roleLabels as $role => $label)
                                    @php $defaults = $caps[$role] ?? ['percent' => 0, 'fixed' => 0]; @endphp
                                    <div class="col-md-4">
                                        <div class="card border" style="background:#fafdfa; border-color:#d6eadc !important;">
                                            <div class="card-body">
                                                <h6 class="fw-bold mb-3" style="color:#14532d;">
                                                    <i class="bi bi-person-badge"></i> {{ $label }}
                                                </h6>
                                                <div class="mb-3">
                                                    <label class="form-label small">حد النسبة (%)</label>
                                                    <input type="number" min="0" max="100" step="0.5"
                                                           name="discount_cap_{{ $role }}_pct"
                                                           class="form-control"
                                                           value="{{ $read('discount_cap_'.$role.'_pct', $defaults['percent']) }}">
                                                </div>
                                                <div class="mb-0">
                                                    <label class="form-label small">حد المبلغ الثابت ({{ $currency }})</label>
                                                    <input type="number" min="0" step="0.01"
                                                           name="discount_cap_{{ $role }}_fixed"
                                                           class="form-control"
                                                           value="{{ $read('discount_cap_'.$role.'_fixed', $defaults['fixed']) }}">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-theme">
                    <div class="settings-card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-palette text-accent"></i> ألوان الهوية</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert bg-primary-transparent border-0 mb-3">
                                <i class="bi bi-info-circle"></i>
                                الألوان الافتراضية تأتي من إعدادات النظام، وأي تعديل هنا يحفظ تخصيصاً خاصاً بلوحة التحكم. زر الاستعادة يرجع الألوان الافتراضية.
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <button type="button" class="theme-preset" data-preset='{"theme_primary":"#164c37","theme_dark":"#0f2d22","theme_header":"#164c37","theme_accent":"#b97818","theme_menu":"#f7f8f5"}'>
                                    <span class="swatch" style="background:#164c37"></span><span class="swatch" style="background:#b97818"></span> الافتراضي
                                </button>
                                <button type="button" class="theme-preset" data-preset='{"theme_primary":"#0f766e","theme_dark":"#134e4a","theme_header":"#0f766e","theme_accent":"#d97706","theme_menu":"#f0fdfa"}'>
                                    <span class="swatch" style="background:#0f766e"></span><span class="swatch" style="background:#d97706"></span> تركواز
                                </button>
                                <button type="button" class="theme-preset" data-preset='{"theme_primary":"#7f1d1d","theme_dark":"#450a0a","theme_header":"#7f1d1d","theme_accent":"#f59e0b","theme_menu":"#fff7ed"}'>
                                    <span class="swatch" style="background:#7f1d1d"></span><span class="swatch" style="background:#f59e0b"></span> دافئ
                                </button>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">الأساسي</label>
                                    <input type="color" name="theme_primary" class="form-control form-control-color color-input w-100"
                                        value="{{ $read('theme_primary', $theme['primary'] ?? '#164c37') }}">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">الغامق</label>
                                    <input type="color" name="theme_dark" class="form-control form-control-color color-input w-100"
                                        value="{{ $read('theme_dark', $theme['dark'] ?? '#0f2d22') }}">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">الهيدر</label>
                                    <input type="color" name="theme_header" class="form-control form-control-color color-input w-100"
                                        value="{{ $read('theme_header', $theme['header'] ?? '#164c37') }}">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">التمييز</label>
                                    <input type="color" name="theme_accent" class="form-control form-control-color color-input w-100"
                                        value="{{ $read('theme_accent', $theme['accent'] ?? '#b97818') }}">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">خلفية المنيو الجانبي</label>
                                    <input type="color" name="theme_menu" class="form-control form-control-color color-input w-100"
                                        value="{{ $read('theme_menu', $theme['menu'] ?? '#f7f8f5') }}">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">نمط الهيدر</label>
                                    @php $headerStyle = $read('theme_header_style', $theme['header_style'] ?? 'color'); @endphp
                                    <select name="theme_header_style" class="form-select">
                                        <option value="light" @selected($headerStyle === 'light')>فاتح</option>
                                        <option value="dark" @selected($headerStyle === 'dark')>غامق</option>
                                        <option value="color" @selected($headerStyle === 'color')>هوية</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">نمط القائمة</label>
                                    @php $menuStyle = $read('theme_menu_style', $theme['menu_style'] ?? 'brand'); @endphp
                                    <select name="theme_menu_style" class="form-select">
                                        <option value="brand" @selected($menuStyle === 'brand')>هوية</option>
                                        <option value="light" @selected($menuStyle === 'light')>فاتح</option>
                                        <option value="dark" @selected($menuStyle === 'dark')>غامق</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('resetThemeForm').submit()" @disabled(!$canEditSettings)>
                                    <i class="bi bi-arrow-counterclockwise"></i> استعادة الألوان الافتراضية
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 text-start" id="settingsSaveRow">
                    <button type="submit" class="btn btn-primary" @disabled(!$canEditSettings)>
                        <i class="bi bi-save"></i> حفظ الإعدادات
                    </button>
                </div>
            </form>

            <div class="tab-pane fade" id="tab-brand">
                <form method="POST" action="{{ route('admin.settings.brand.update') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="settings-card card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-image text-accent"></i> الشعار والأيقونة</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">شعار الموقع</label>
                                    <div class="brand-asset-preview mb-3">
                                        <img src="{{ Brand::logoUrl() }}" alt="Logo">
                                    </div>
                                    <input type="file" name="brand_logo" class="form-control" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                                    <div class="setting-hint mt-2">PNG / JPG / WEBP / SVG، ويفضل خلفية شفافة.</div>
                                    @if(Brand::hasCustomLogo())
                                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="document.getElementById('del-logo').submit()">
                                            <i class="bi bi-trash"></i> حذف الشعار
                                        </button>
                                    @endif
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">أيقونة التبويب</label>
                                    <div class="brand-asset-preview mb-3">
                                        <img src="{{ Brand::faviconUrl() }}" alt="Favicon" style="max-width:70px; max-height:70px;">
                                    </div>
                                    <input type="file" name="brand_favicon" class="form-control" accept="image/png,image/x-icon,image/webp,image/svg+xml">
                                    <div class="setting-hint mt-2">يفضل صورة مربعة 64x64 أو 128x128.</div>
                                    @if(Brand::hasCustomFavicon())
                                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" onclick="document.getElementById('del-favicon').submit()">
                                            <i class="bi bi-trash"></i> حذف الأيقونة
                                        </button>
                                    @endif
                                </div>
                            </div>
                            <div class="text-start mt-4">
                                <button class="btn btn-primary" @disabled(!$canEditSettings)>
                                    <i class="bi bi-cloud-upload"></i> حفظ الشعار
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="tab-pane fade" id="tab-currencies">
                <div class="alert bg-primary-transparent border-0">
                    <i class="bi bi-info-circle"></i>
                    العملات هنا للعرض للزبون فقط. المحاسبة والفواتير تحفظ بالقيمة الأساسية.
                </div>

                <div class="settings-card card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-coin text-accent"></i> العملات</h5>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addCurrencyCollapse">
                            <i class="bi bi-plus-lg"></i> إضافة عملة
                        </button>
                    </div>
                    <div class="collapse" id="addCurrencyCollapse">
                        <div class="card-body border-bottom">
                            <form method="POST" action="{{ route('admin.currencies.store') }}">
                                @csrf
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-2">
                                        <label class="form-label">الرمز</label>
                                        <input type="text" name="code" class="form-control" maxlength="3" placeholder="USD" dir="ltr" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">الاسم</label>
                                        <input type="text" name="name" class="form-control" placeholder="دولار أمريكي" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">الرمز المالي</label>
                                        <input type="text" name="symbol" class="form-control" placeholder="$" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">سعر الصرف للأساسية</label>
                                        <input type="number" step="0.000001" min="0.000001" name="rate_to_base" class="form-control" placeholder="0.71" required>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-primary w-100" @disabled(!$canEditSettings)>إضافة</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.currencies.update-rates') }}">
                        @csrf
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th>الرمز</th>
                                        <th>الاسم</th>
                                        <th>الرمز المالي</th>
                                        <th>سعر الصرف</th>
                                        <th>نشطة</th>
                                        <th>آخر تحديث</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($currencies as $currency)
                                        <tr>
                                            <td>
                                                <strong>{{ $currency->code }}</strong>
                                                @if($currency->is_base)
                                                    <span class="badge bg-primary-transparent ms-1">أساسية</span>
                                                @endif
                                            </td>
                                            <td>{{ $currency->name }}</td>
                                            <td><code>{{ $currency->symbol }}</code></td>
                                            <td>
                                                @if($currency->is_base)
                                                    <span class="fw-bold">1.000000</span>
                                                @else
                                                    <input type="number" step="0.000001" min="0.000001"
                                                        name="rates[{{ $currency->id }}]" value="{{ $currency->rate_to_base }}"
                                                        class="form-control form-control-sm" style="max-width:180px">
                                                @endif
                                            </td>
                                            <td>
                                                @if($currency->is_base)
                                                    <i class="bi bi-check-circle-fill text-success"></i>
                                                @else
                                                    <input type="hidden" name="active[{{ $currency->id }}]" value="0">
                                                    <div class="form-check form-switch">
                                                        <input type="checkbox" name="active[{{ $currency->id }}]" value="1"
                                                            class="form-check-input" @checked($currency->is_active)>
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-muted small">{{ $currency->rate_updated_at?->diffForHumans() ?? '—' }}</td>
                                            <td class="text-end">
                                                @unless($currency->is_base)
                                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                                        onclick="if(confirm('حذف هذه العملة؟')) document.getElementById('delCurr{{ $currency->id }}').submit()">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7">
                                                <x-admin.empty-state icon="bi-currency-exchange" message="لا توجد عملات بعد" compact />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer text-start">
                            <button class="btn btn-primary" @disabled(!$canEditSettings)>
                                <i class="bi bi-save"></i> حفظ العملات
                            </button>
                        </div>
                    </form>
                </div>

                @foreach($currencies as $currency)
                    @unless($currency->is_base)
                        <form id="delCurr{{ $currency->id }}" method="POST" action="{{ route('admin.currencies.destroy', $currency) }}" class="d-none">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endunless
                @endforeach
            </div>
        </div>

        <form id="resetThemeForm" action="{{ route('admin.settings.reset-theme') }}" method="POST" class="d-none">@csrf</form>
        <form id="del-logo" method="POST" action="{{ route('admin.settings.brand.delete', 'brand_logo') }}" class="d-none">@csrf @method('DELETE')</form>
        <form id="del-favicon" method="POST" action="{{ route('admin.settings.brand.delete', 'brand_favicon') }}" class="d-none">@csrf @method('DELETE')</form>
    </div>
</div>

<script>
    (function () {
        const saveRow = document.getElementById('settingsSaveRow');
        const mainTabs = ['#tab-general', '#tab-billing', '#tab-operations', '#tab-discounts', '#tab-theme'];

        function syncSaveRow() {
            const active = document.querySelector('.settings-shell .tab-pane.active')?.id;
            if (!saveRow) return;
            saveRow.style.display = mainTabs.includes('#' + active) ? '' : 'none';
        }

        document.querySelectorAll('.settings-shell [data-bs-toggle="tab"]').forEach((tab) => {
            tab.addEventListener('shown.bs.tab', () => {
                history.replaceState(null, '', tab.getAttribute('href'));
                syncSaveRow();
            });
        });

        if (location.hash && document.querySelector(`.settings-shell [href="${location.hash}"]`) && window.bootstrap) {
            new bootstrap.Tab(document.querySelector(`.settings-shell [href="${location.hash}"]`)).show();
        }
        syncSaveRow();

        document.querySelectorAll('.theme-preset').forEach((button) => {
            button.addEventListener('click', () => {
                const preset = JSON.parse(button.dataset.preset || '{}');
                Object.entries(preset).forEach(([name, value]) => {
                    const input = document.querySelector(`[name="${name}"]`);
                    if (input) input.value = value;
                });
            });
        });
    })();
</script>
@endsection
