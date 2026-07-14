@extends('layouts.admin')
@section('title', $announcement->exists ? 'تعديل إعلان' : 'إعلان جديد')

@section('content')
@php
    $isEdit       = $announcement->exists;
    $action       = $isEdit ? route('admin.announcements.update', $announcement) : route('admin.announcements.store');
    $audience     = $announcement->audience_filter ?? [];
    $isPublished  = $announcement->status === 'published';

    // Curated icon catalogue — keyed by Bootstrap-icons class so the
    // template binds to it directly. Each entry has a friendly Arabic
    // label shown in the picker tooltip / aria-label.
    $iconCatalogue = [
        'bi-megaphone-fill'        => 'إعلان',
        'bi-percent'               => 'خصم',
        'bi-stars'                 => 'مميز',
        'bi-gift-fill'             => 'هدية',
        'bi-calendar-heart-fill'   => 'مناسبة',
        'bi-cup-hot-fill'          => 'منيو جديد',
        'bi-cake2-fill'            => 'عيد ميلاد',
        'bi-fire'                  => 'حصري',
        'bi-flag-fill'             => 'وطني',
        'bi-moon-stars-fill'       => 'رمضان',
        'bi-snow'                  => 'شتوي',
        'bi-sun-fill'              => 'صيفي',
        'bi-truck'                 => 'توصيل',
        'bi-bag-heart-fill'        => 'مفضّل',
        'bi-trophy-fill'           => 'مكافأة',
        'bi-bell-fill'             => 'تنبيه',
    ];

    // Curated color palette — brand-first. Custom hex always available.
    $palette = [
        '#F9A825' => 'ذهبي',
        '#16A34A' => 'أخضر',
        '#0EA5E9' => 'أزرق',
        '#7C3AED' => 'بنفسجي',
        '#DC2626' => 'أحمر',
        '#EA580C' => 'برتقالي',
        '#0F766E' => 'تركواز',
        '#0F2D22' => 'أخضر داكن',
        '#475569' => 'رمادي',
        '#DB2777' => 'وردي',
    ];
@endphp

<x-admin.breadcrumb
    :title="$isEdit ? 'تعديل إعلان' : 'إعلان جديد'"
    icon="bi-megaphone-fill"
    :crumbs="[['label' => 'العروض والإعلانات', 'url' => route('admin.announcements.index')]]" />

@if($isPublished)
    <div class="ann-publish-warn mb-3">
        <i class="bi bi-broadcast-pin"></i>
        <div>
            <strong>هذا الإعلان منشور.</strong>
            @if($announcement->isGuestsAudience())
                يظهر حالياً كبانر على <strong>شاشة المنيو</strong> للزبائن الغير مسجَّلين.
                تقدر تعدّل المحتوى (العنوان، النص، اللون، الأيقونة، CTA، الصورة) ويتحدّث البانر فوراً.
            @else
                تم إرساله لـ <strong>{{ number_format($announcement->recipients_count) }}</strong> زبون.
                تقدر تعدّل المحتوى (العنوان، النص، اللون، الأيقونة، CTA، الصورة) — التعديلات تظهر للمستلمين الجدد وفي البانر الحي.
            @endif
            <strong>الجمهور والجدولة مغلقة</strong> لأن النشر تم بالفعل.
        </div>
    </div>
@endif

{{-- Define window.annForm INLINE here, BEFORE the <form x-data="annForm(...)">.
     Pushing to a `scripts` stack puts the definition at the end of the
     <body>, but Alpine evaluates `x-data` as soon as it sees the element
     (before stack content runs). The function would be undefined at eval
     time, every binding silently resolves to empty, and the icon grid /
     color palette / preview all render blank. Inline = guaranteed before. --}}
<script>
    window.annForm = function (config) {
        const labels = {
            'bi-megaphone-fill':      'إعلان',
            'bi-percent':             'خصم',
            'bi-stars':               'مميز',
            'bi-gift-fill':           'هدية',
            'bi-calendar-heart-fill': 'مناسبة',
            'bi-cup-hot-fill':        'منيو جديد',
            'bi-cake2-fill':          'عيد ميلاد',
            'bi-fire':                'حصري',
            'bi-flag-fill':           'وطني',
            'bi-moon-stars-fill':     'رمضان',
            'bi-snow':                'شتوي',
            'bi-sun-fill':            'صيفي',
            'bi-truck':               'توصيل',
            'bi-bag-heart-fill':      'مفضّل',
            'bi-trophy-fill':         'مكافأة',
            'bi-bell-fill':           'تنبيه',
        };
        return {
            audienceType: config.audienceType,
            color:        config.color,
            icon:         config.icon,
            title:        config.title,
            body:         config.body,
            ctaText:      config.ctaText,
            palette:      config.palette,
            iconLabel(iconClass) { return labels[iconClass] || iconClass; },
        };
    };
</script>

<form action="{{ $action }}" method="POST" enctype="multipart/form-data" x-data='annForm({
        audienceType: @json($announcement->audience_type ?? 'all'),
        color: @json($announcement->color ?? '#F9A825'),
        icon: @json($announcement->icon ?? 'bi-megaphone-fill'),
        title: @json($announcement->title ?? ''),
        body: @json($announcement->body ?? ''),
        ctaText: @json($announcement->cta_text ?? ''),
        palette: @json(array_keys($palette))
    })'>
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-3">
        {{-- ═══════════════ FORM ═══════════════ --}}
        <div class="col-lg-8">
            {{-- ─── Content card ─────────────────────────────── --}}
            <div class="card mb-3">
                <div class="card-header"><strong><i class="bi bi-pencil-square"></i> محتوى الإعلان</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">عنوان الإعلان <span class="text-danger">*</span></label>
                        <input type="text" name="title" x-model="title" maxlength="200"
                               value="{{ old('title', $announcement->title) }}"
                               class="form-control @error('title') is-invalid @enderror"
                               placeholder="مثال: عرض رمضان الكريم — 20% خصم" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">نص الإعلان <span class="text-danger">*</span></label>
                        <textarea name="body" x-model="body" rows="3" maxlength="2000"
                                  class="form-control @error('body') is-invalid @enderror"
                                  placeholder="اشرح العرض بوضوح. مثال: استمتع بخصم 20% على جميع الأطباق طوال شهر رمضان عند الطلب من البوابة." required>{{ old('body', $announcement->body) }}</textarea>
                        @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">نص زر الإجراء <small class="text-muted">(اختياري)</small></label>
                            <input type="text" name="cta_text" x-model="ctaText" maxlength="80"
                                   value="{{ old('cta_text', $announcement->cta_text) }}"
                                   class="form-control" placeholder="اطلب الآن">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">رابط زر الإجراء <small class="text-muted">(اختياري)</small></label>
                            <input type="text" name="cta_url" maxlength="500" dir="ltr"
                                   value="{{ old('cta_url', $announcement->cta_url) }}"
                                   class="form-control" placeholder="/portal/order">
                        </div>
                    </div>

                    {{-- ─── Visual icon grid ─────────────────────── --}}
                    <div class="mt-3">
                        <label class="form-label d-flex align-items-center justify-content-between">
                            <span>
                                الأيقونة
                                <small class="text-muted ms-1" title="اضغط على الأيقونة المناسبة لإعلانك. اللون يتبع الباليت أدناه.">
                                    <i class="bi bi-info-circle"></i>
                                </small>
                            </span>
                            <small class="text-muted" x-text="'مختارة: ' + (iconLabel(icon) || '—')"></small>
                        </label>
                        {{-- Hidden field carries the actual value to the server. --}}
                        <input type="hidden" name="icon" :value="icon">
                        <div class="ann-icon-grid">
                            @foreach($iconCatalogue as $iconClass => $iconLabel)
                                <button type="button" class="ann-icon-card"
                                        :class="icon === '{{ $iconClass }}' ? 'is-active' : ''"
                                        :style="icon === '{{ $iconClass }}' ? `background:${color}15; border-color:${color};` : ''"
                                        @click="icon = '{{ $iconClass }}'"
                                        title="{{ $iconLabel }}"
                                        aria-label="{{ $iconLabel }}">
                                    <i class="bi {{ $iconClass }}"
                                       :style="icon === '{{ $iconClass }}' ? `color:${color};` : ''"></i>
                                    <span class="ann-icon-card__label">{{ $iconLabel }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- ─── Color palette ───────────────────────── --}}
                    <div class="mt-3">
                        <label class="form-label d-flex align-items-center justify-content-between">
                            <span>
                                اللون
                                <small class="text-muted ms-1" title="اختر من الباليت أو حدّد لون مخصص">
                                    <i class="bi bi-info-circle"></i>
                                </small>
                            </span>
                            <span class="ann-color-current" :style="`background:${color};`"
                                  :title="color"></span>
                        </label>
                        <input type="hidden" name="color" :value="color">
                        <div class="ann-color-palette">
                            @foreach($palette as $hex => $label)
                                {{-- Use Blade-rendered static `style` (NOT Alpine :style)
                                     so the chip background is in the HTML at first paint
                                     and Bootstrap's .btn rules can't shadow the Alpine
                                     binding. The hex never changes per chip; the only
                                     reactive bit is the .is-active class. --}}
                                <button type="button" class="ann-color-chip"
                                        :class="color === '{{ $hex }}' ? 'is-active' : ''"
                                        style="background-color: {{ $hex }} !important;"
                                        @click="color = '{{ $hex }}'"
                                        title="{{ $label }}"
                                        aria-label="{{ $label }}">
                                    <i class="bi bi-check-lg"
                                       x-show="color === '{{ $hex }}'"></i>
                                </button>
                            @endforeach
                            <label class="ann-color-custom" title="لون مخصص">
                                <input type="color" :value="color" @input="color = $event.target.value"
                                       class="ann-color-custom__input">
                                <i class="bi bi-eyedropper"></i>
                            </label>
                        </div>
                    </div>

                    {{-- ─── Image upload ────────────────────────── --}}
                    <div class="mt-3">
                        <label class="form-label">صورة <small class="text-muted">(اختيارية، تُعرض فوق النص)</small></label>
                        <input type="file" name="image" accept="image/*" class="form-control">
                        @if($announcement->image_path)
                            <div class="mt-2 d-flex align-items-center gap-2">
                                <img src="{{ asset('storage/'.$announcement->image_path) }}"
                                     alt="" style="max-height:80px; border-radius:8px;">
                                <small class="text-muted">الصورة الحالية — ارفع جديدة لاستبدالها.</small>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ─── Audience card ──────────────────────────────── --}}
            <div class="card mb-3 {{ $isPublished ? 'ann-locked-card' : '' }}">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <strong><i class="bi bi-people-fill"></i> الجمهور المستهدف</strong>
                    @if($isPublished)
                        <small class="text-muted"><i class="bi bi-lock-fill"></i> مغلق بعد النشر</small>
                    @endif
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">من سيستلم هذا الإعلان؟</label>
                        <select name="audience_type" x-model="audienceType" class="form-select" {{ $isPublished ? 'disabled' : '' }}>
                            <optgroup label="الزبائن المسجَّلون (إشعار في حسابهم)">
                                <option value="all">كل الزبائن النشطين</option>
                                <option value="tier">حسب مستوى الولاء</option>
                                <option value="inactive_days">الزبائن غير النشطين مؤخراً</option>
                                <option value="specific">زبائن محددون</option>
                            </optgroup>
                            <optgroup label="الزوّار غير المسجَّلين (بانر على المنيو)">
                                <option value="guests">زبائن غير مسجّلين — لتحفيز التسجيل</option>
                            </optgroup>
                        </select>
                    </div>

                    {{-- Contextual hint for the guests audience.
                         This is a different distribution channel — it's
                         not a notification, it's a banner on the public
                         menu screen for anonymous QR sessions. Spell it
                         out so admins don't expect it in the customer
                         portal inbox or in recipient counts. --}}
                    <div x-show="audienceType === 'guests'" x-cloak
                         class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3"
                         style="font-size: .85rem;">
                        <i class="bi bi-megaphone-fill fs-5 mt-1"></i>
                        <div>
                            <strong class="d-block mb-1">قناة عرض مختلفة</strong>
                            هذا الإعلان رح يظهر <strong>بانر على شاشة المنيو</strong> للزبائن اللي ما عندهم حساب (طلب كضيف).
                            <br>
                            <span class="text-muted">لن يُرسَل كإشعار. عداد المستلمين يبقى صفراً — البانر يُحتسب بالمشاهدات لا بالإشعارات.</span>
                            <div class="mt-2 small">
                                <i class="bi bi-lightbulb"></i>
                                نصيحة: اربطه بـ
                                <strong>زرّ "إنشاء حساب"</strong>
                                عبر حقل CTA أدناه لتعظيم التحويل من ضيف إلى زبون.
                            </div>
                        </div>
                    </div>

                    <div x-show="audienceType === 'tier'" x-cloak>
                        <label class="form-label small">الحد الأدنى للمستوى</label>
                        <select name="audience_filter[min_tier]" class="form-select form-select-sm" {{ $isPublished ? 'disabled' : '' }}>
                            <option value="bronze" @selected(($audience['min_tier'] ?? null) === 'bronze')>برونزي فأعلى</option>
                            <option value="silver" @selected(($audience['min_tier'] ?? 'silver') === 'silver')>فضي فأعلى</option>
                            <option value="gold"   @selected(($audience['min_tier'] ?? null) === 'gold')>ذهبي فقط</option>
                        </select>
                    </div>

                    <div x-show="audienceType === 'inactive_days'" x-cloak>
                        <label class="form-label small">عدد الأيام منذ آخر زيارة</label>
                        <input type="number" name="audience_filter[days]" min="1" max="365"
                               value="{{ $audience['days'] ?? 30 }}" class="form-control form-control-sm"
                               style="max-width:140px;" {{ $isPublished ? 'readonly' : '' }}>
                    </div>

                    <div x-show="audienceType === 'specific'" x-cloak>
                        <label class="form-label small">معرّفات الزبائن (مفصولة بفواصل)</label>
                        <input type="text"
                               value="{{ implode(',', $audience['customer_ids'] ?? []) }}"
                               class="form-control form-control-sm" dir="ltr"
                               {{ $isPublished ? 'readonly' : '' }}
                               x-data="{}"
                               @input="
                                    document.querySelectorAll('input[name^=\'audience_filter[customer_ids]\']').forEach(e => e.remove());
                                    $event.target.value.split(',').filter(Boolean).forEach(id => {
                                        const i = document.createElement('input');
                                        i.type = 'hidden';
                                        i.name = 'audience_filter[customer_ids][]';
                                        i.value = id.trim();
                                        $event.target.parentElement.appendChild(i);
                                    });">
                    </div>
                </div>
            </div>

            {{-- ─── Schedule card ──────────────────────────────── --}}
            <div class="card mb-3 {{ $isPublished ? 'ann-locked-card' : '' }}">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <strong><i class="bi bi-calendar-event"></i> الجدولة (اختيارية)</strong>
                    @if($isPublished)
                        <small class="text-muted"><i class="bi bi-lock-fill"></i> مغلق بعد النشر</small>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small">يبدأ في</label>
                            <input type="datetime-local" name="starts_at" class="form-control"
                                   value="{{ old('starts_at', optional($announcement->starts_at)->format('Y-m-d\TH:i')) }}"
                                   {{ $isPublished ? 'readonly' : '' }}>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">ينتهي في</label>
                            <input type="datetime-local" name="ends_at" class="form-control"
                                   value="{{ old('ends_at', optional($announcement->ends_at)->format('Y-m-d\TH:i')) }}"
                                   {{ $isPublished ? 'readonly' : '' }}>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">اتركها فارغة للإعلان الدائم. يحدد متى يظهر/يختفي البانر تلقائياً.</small>
                </div>
            </div>

            {{-- ─── Scope card ─────────────────────────────────── --}}
            <div class="card mb-3 {{ $isPublished ? 'ann-locked-card' : '' }}">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <strong><i class="bi bi-shop"></i> النطاق</strong>
                    @if($isPublished)
                        <small class="text-muted"><i class="bi bi-lock-fill"></i> مغلق بعد النشر</small>
                    @endif
                </div>
                <div class="card-body">
                    @if($branchLocked)
                        @php
                            $lockedBranch = $branches->firstWhere('id', $announcement->branch_id ?? $branches->first()?->id) ?? $branches->first();
                        @endphp
                        <input type="hidden" name="branch_id" value="{{ $lockedBranch?->id }}">
                        <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f0fdf4; border:1px solid #bbf7d0;">
                            <i class="bi bi-building text-success"></i>
                            <div class="flex-grow-1">
                                <strong class="d-block">{{ $lockedBranch?->name ?? '—' }}</strong>
                                <small class="text-muted">سيُرسل هذا الإعلان لزبائن هذا الفرع فقط.</small>
                            </div>
                            <i class="bi bi-lock-fill text-muted" title="مقيّد بفرعك"></i>
                        </div>
                    @else
                        <label class="form-label">الفرع المستهدف</label>
                        <select name="branch_id" class="form-select" {{ $isPublished ? 'disabled' : '' }}>
                            @if($canPostGlobal)
                                <option value="">كل الفروع (إعلان عام)</option>
                            @endif
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}" @selected(old('branch_id', $announcement->branch_id) == $b->id)>
                                    {{ $b->name }}
                                </option>
                            @endforeach
                        </select>
                    @endif
                </div>
            </div>
        </div>

        {{-- ═══════════════ LIVE PREVIEW (sticky) ═══════════════ --}}
        <div class="col-lg-4">
            <div class="ann-preview-wrap">
                <div class="card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="bi bi-phone"></i>
                        <strong>معاينة كما يراها الزبون</strong>
                    </div>
                    <div class="card-body">
                        <div class="ann-preview"
                             :style="`background: linear-gradient(135deg, ${color}15, ${color}05); border-color: ${color}40;`">
                            <div class="ann-preview__icon" :style="`background: ${color}; color: white;`">
                                <i class="bi" :class="icon"></i>
                            </div>
                            <div class="ann-preview__body">
                                <strong x-text="title || 'عنوان الإعلان'"></strong>
                                <p x-text="body || 'نص الإعلان يظهر هنا...'"></p>
                                <button type="button" class="ann-preview__cta"
                                        :style="`background: ${color};`"
                                        x-show="ctaText"
                                        x-text="ctaText"></button>
                            </div>
                        </div>

                        @if($isEdit && isset($estimatedAudience))
                            <div class="alert alert-info mt-3 mb-0 d-flex align-items-center gap-2"
                                 style="font-size:.85rem;">
                                <i class="bi bi-people"></i>
                                <div>
                                    سيُرسل لـ <strong>{{ number_format($estimatedAudience) }}</strong> زبون
                                    بناءً على الفلتر الحالي.
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="bi bi-save"></i>
                            {{ $isEdit ? 'حفظ التعديلات' : 'حفظ كمسوّدة' }}
                        </button>
                        <a href="{{ route('admin.announcements.index') }}" class="btn btn-light">إلغاء</a>
                    </div>
                </div>

                @if($isEdit && $announcement->status === 'draft')
                    <div class="card border-success mt-3">
                        <div class="card-body text-center">
                            <h6 class="fw-bold text-success mb-2">
                                <i class="bi bi-rocket-takeoff-fill"></i> جاهز للنشر؟
                            </h6>
                            <p class="small text-muted mb-3">احفظ التعديلات أولاً، ثم اضغط نشر من القائمة.</p>
                            <a href="{{ route('admin.announcements.index') }}" class="btn btn-success btn-sm">
                                <i class="bi bi-arrow-left"></i> الذهاب للقائمة
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</form>

@push('styles')
<style>
    /* ─── Publish-state warning banner ─────────────────────────── */
    .ann-publish-warn {
        display: flex; align-items: flex-start; gap: .75rem;
        background: linear-gradient(135deg, #fef3c7, #fef9e7);
        border: 1px solid #fcd34d;
        border-radius: 12px;
        padding: 1rem 1.1rem;
        color: #78350f;
    }
    .ann-publish-warn i { font-size: 1.4rem; color: #d97706; flex-shrink: 0; }

    /* ─── Locked cards (sections frozen post-publish) ──────────── */
    .ann-locked-card { opacity: .85; }
    .ann-locked-card .card-body {
        background: repeating-linear-gradient(
            45deg, transparent, transparent 8px,
            rgba(15, 23, 42, .02) 8px, rgba(15, 23, 42, .02) 16px
        );
    }

    /* ─── Visual icon grid ─────────────────────────────────────── */
    .ann-icon-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
        gap: .5rem;
    }
    .ann-icon-card {
        display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: .35rem;
        padding: .75rem .5rem;
        border: 2px solid #e2e8f0;
        background: #fff;
        border-radius: 12px;
        cursor: pointer;
        transition: transform .15s ease, border-color .15s ease, background .15s ease;
        min-height: 80px;
    }
    .ann-icon-card:hover {
        transform: translateY(-2px);
        border-color: #cbd5e1;
        background: #f8fafc;
    }
    .ann-icon-card.is-active {
        border-width: 2px;
        box-shadow: 0 4px 12px -4px rgba(15, 23, 42, .12);
        transform: translateY(-2px);
    }
    .ann-icon-card i {
        font-size: 1.5rem;
        color: #64748b;
        transition: color .15s;
    }
    .ann-icon-card.is-active i { font-weight: 600; }
    .ann-icon-card__label {
        font-size: .68rem;
        font-weight: 600;
        color: #64748b;
        text-align: center;
        line-height: 1.2;
    }
    .ann-icon-card.is-active .ann-icon-card__label { color: #0f172a; }

    /* ─── Color palette ────────────────────────────────────────── */
    .ann-color-palette {
        display: flex; flex-wrap: wrap; gap: .5rem;
        align-items: center;
    }
    .ann-color-chip {
        width: 36px; height: 36px;
        border-radius: 10px;
        border: 2px solid transparent;
        cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        color: white;
        font-size: 1rem;
        transition: transform .15s, box-shadow .15s;
        position: relative;
    }
    .ann-color-chip:hover {
        transform: scale(1.1);
        box-shadow: 0 2px 8px rgba(0, 0, 0, .15);
    }
    .ann-color-chip.is-active {
        border-color: #0f172a;
        box-shadow: 0 0 0 3px white, 0 0 0 5px #0f172a;
    }
    .ann-color-custom {
        width: 36px; height: 36px;
        border-radius: 10px;
        border: 2px dashed #cbd5e1;
        cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        color: #64748b;
        margin: 0;
        position: relative;
        overflow: hidden;
    }
    .ann-color-custom:hover { border-color: #94a3b8; color: #475569; }
    .ann-color-custom__input {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
    }
    .ann-color-current {
        display: inline-block;
        width: 22px; height: 22px;
        border-radius: 6px;
        border: 1.5px solid #e2e8f0;
        vertical-align: middle;
    }

    /* ─── Live preview ─────────────────────────────────────────── */
    .ann-preview-wrap {
        position: sticky;
        top: 80px;
    }
    .ann-preview {
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        transition: background .2s, border-color .2s;
    }
    .ann-preview__icon {
        width: 48px; height: 48px;
        border-radius: 12px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.3rem;
        flex-shrink: 0;
        transition: background .2s;
    }
    .ann-preview__body { flex: 1; min-width: 0; }
    .ann-preview__body strong {
        display: block; font-size: 1.05rem;
        color: #0f172a; margin-bottom: 6px;
        word-wrap: break-word;
    }
    .ann-preview__body p {
        font-size: .88rem; color: #475569;
        margin: 0 0 12px; line-height: 1.7;
        word-wrap: break-word;
    }
    .ann-preview__cta {
        border: 0; padding: 8px 18px;
        border-radius: 9px;
        color: white; font-weight: 700; font-size: .85rem;
        cursor: pointer;
        transition: background .2s, transform .15s;
    }
    .ann-preview__cta:hover { transform: translateY(-1px); }

    [x-cloak] { display: none !important; }

    @media (max-width: 992px) {
        .ann-preview-wrap { position: static; }
    }
</style>
@endpush

{{-- annForm() is defined inline above (before the <form>) to guarantee it's
     loaded before Alpine evaluates the form's x-data. Don't push it again
     here — duplicate definition is harmless but the inline one is the SOT. --}}
@endsection

@push('scripts')
    {{-- The admin layout loads @livewireStyles but NOT @livewireScripts
         (Livewire bundles Alpine; loading it on every admin page is wasteful
         when most pages don't need reactivity). This form is fully Alpine-
         driven (icon grid, color palette, live preview, audience switching),
         so we explicitly request the bundle here. Without it, x-data /
         x-show / @click are inert HTML attributes and the page renders as
         a static skeleton. --}}
    @livewireScripts
@endpush
