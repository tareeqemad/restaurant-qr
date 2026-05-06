@extends('customer.layout')
@section('title','القائمة')
@section('content')

@php
    $restaurantName = \App\Models\Setting::get('site_name', config('restaurant.name'));
    $restaurantLogoUrl = \App\Helpers\Brand::logoUrl();
    $tableNumber = $session->table->number ?? '—';
    $branchName = $session->table?->branch?->name;
    // Recognised diner: portal session > cookie-linked customer > stamped name.
    // If one of the first two is set, the customer is a known account — we
    // skip the name+phone fields in the cart drawer entirely.
    $linkedCustomer = $portalCustomer ?: $session->customer;
    $dinerName = $linkedCustomer?->name ?: $session->customer_name;
    $sectionsCount = $categories->count() + ($featured->count() ? 1 : 0);
    $itemsCount = $categories->sum(fn ($category) => $category->menuItems->count());
    $defaultCustomerName = $session->customer_name ?: ($portalCustomer?->name ?? '');
    $returnToMenu = route('customer.menu');
    $portalLoginUrl = route('portal.login', ['return_to' => $returnToMenu]);
    $portalRegisterUrl = route('portal.register', ['return_to' => $returnToMenu]);
    $currencySymbol = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol'));
    $taxEnabled = (bool) \App\Models\Setting::get('tax_enabled', config('restaurant.tax.enabled'));
    $taxRate = $taxEnabled ? (float) \App\Models\Setting::get('tax_rate', config('restaurant.tax.rate')) : 0.0;
    $serviceEnabled = (bool) \App\Models\Setting::get('service_enabled', config('restaurant.service_charge.enabled'));
    $serviceRate = $serviceEnabled ? (float) \App\Models\Setting::get('service_rate', config('restaurant.service_charge.rate')) : 0.0;
    $customerTaxDisplay = $session->table?->branch?->customerTaxDisplayMode()
        ?? \App\Models\Setting::get('customer_tax_display', 'exclusive');
    $customerTaxDisplay = in_array($customerTaxDisplay, ['exclusive', 'inclusive'], true) ? $customerTaxDisplay : 'exclusive';
@endphp

<div x-data="menuApp()" @keydown.escape.window="closeAll()" class="qr-menu-page">

    <section class="menu-hero">
        <div class="menu-hero-copy">
            <div class="menu-eyebrow">
                <span><i class="bi bi-qr-code-scan"></i> طلب من الطاولة</span>
                @if($branchName)
                    <span><i class="bi bi-shop"></i> {{ $branchName }}</span>
                @endif
            </div>
            <h1>{{ $dinerName ? 'أهلاً '.$dinerName : 'شو حاب تطلب؟' }}</h1>

            <div class="menu-hero-actions">
                <button type="button" class="hero-cart-btn" @click="openCart()" :disabled="cartCount === 0">
                    <i class="bi bi-basket2-fill"></i>
                    <span x-show="cartCount === 0">السلة فارغة</span>
                    <span x-show="cartCount > 0"><span x-text="cartCount"></span> صنف في السلة</span>
                </button>
                @if($linkedCustomer)
                    <span class="client-pill is-linked">
                        <i class="bi bi-person-check-fill"></i>
                        {{ $linkedCustomer->name }}
                    </span>
                @else
                    <span class="client-pill">
                        <i class="bi bi-person"></i>
                        طلب كضيف
                    </span>
                @endif
            </div>
        </div>

        <div class="menu-hero-panel">
            <div class="hero-stat-grid">
                <div class="hero-stat">
                    <span class="hero-stat-icon" aria-hidden="true">
                        <i class="bi bi-grid-1x2-fill"></i>
                    </span>
                    <div class="hero-stat-body">
                        <span class="hero-stat-label">الأقسام</span>
                        <strong class="hero-stat-value">{{ $sectionsCount }}</strong>
                        <span class="hero-stat-meta">قسم متاح اليوم</span>
                    </div>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-icon hero-stat-icon--accent" aria-hidden="true">
                        <i class="bi bi-egg-fried"></i>
                    </span>
                    <div class="hero-stat-body">
                        <span class="hero-stat-label">الأصناف</span>
                        <strong class="hero-stat-value">{{ $itemsCount }}</strong>
                        <span class="hero-stat-meta">طبق على المنيو</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="menu-command-bar" aria-label="أدوات القائمة">
        <label class="menu-search">
            <i class="bi bi-search"></i>
            <input type="search" x-model.debounce.180ms="search" placeholder="ابحث في المنيو..." autocomplete="off">
            <button type="button" x-show="search" x-cloak @click="search = ''" aria-label="مسح البحث">
                <i class="bi bi-x-lg"></i>
            </button>
        </label>

        <div class="cat-tabs" aria-label="أقسام القائمة"
             x-data="tabsSlider()" x-init="$nextTick(() => init())">
            <button type="button" class="slider-arrow slider-arrow-prev tabs-arrow"
                    x-show="canPrev" x-cloak @click="slide(-1)" aria-label="السابق">‹</button>
            <div class="cat-tabs-scroll" id="catScroll" x-ref="scroll" @scroll.passive="update()">
                @if($featured->count())
                    <a href="#cat-featured" class="cat-tab" :class="activeCat === 'featured' ? 'active' : ''"
                       @click.prevent="scrollTo('featured')">
                        <i class="bi bi-star-fill"></i>
                        مميزة اليوم
                    </a>
                @endif
                @foreach($categories as $cat)
                    <a href="#cat-{{ $cat->id }}" class="cat-tab" :class="activeCat === '{{ $cat->id }}' ? 'active' : ''"
                       @click.prevent="scrollTo('{{ $cat->id }}')">
                        <i class="bi {{ $cat->icon ?: 'bi-tag' }}"></i>
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
            <button type="button" class="slider-arrow slider-arrow-next tabs-arrow"
                    x-show="canNext" x-cloak @click="slide(1)" aria-label="التالي">›</button>
        </div>
    </section>

    <div class="menu-layout menu-layout-modern">
        <aside class="menu-aside">
            <div class="side-tabs">
                <div class="side-tabs-title">
                    <span>الأقسام</span>
                    <span class="title-sub">{{ $sectionsCount }}</span>
                </div>
                <div class="side-tabs-divider"></div>

                @if($featured->count())
                    <a href="#cat-featured" class="side-tab-v" :class="activeCat === 'featured' ? 'active' : ''"
                       @click.prevent="scrollTo('featured')">
                        <span class="tab-icon"><i class="bi bi-star-fill"></i></span>
                        <span class="tab-name">مميزة اليوم</span>
                        <span class="count-bubble">{{ $featured->count() }}</span>
                    </a>
                @endif
                @foreach($categories as $cat)
                    <a href="#cat-{{ $cat->id }}" class="side-tab-v" :class="activeCat === '{{ $cat->id }}' ? 'active' : ''"
                       @click.prevent="scrollTo('{{ $cat->id }}')">
                        <span class="tab-icon"><i class="bi {{ $cat->icon ?: 'bi-tag' }}"></i></span>
                        <span class="tab-name">{{ $cat->name }}</span>
                        <span class="count-bubble">{{ $cat->menuItems->count() }}</span>
                    </a>
                @endforeach

                <div class="menu-client-card">
                    @if($portalCustomer)
                        <span class="menu-client-avatar">{{ $portalCustomer->initial }}</span>
                        <div>
                            <strong>{{ $portalCustomer->name }}</strong>
                            <small>طلبك سيرتبط بحسابك وتاريخ طلباتك.</small>
                        </div>
                    @else
                        <span class="menu-client-avatar"><i class="bi bi-qr-code-scan"></i></span>
                        <div>
                            <strong>جلسة QR</strong>
                            <small>تقدر تطلب بدون تسجيل، والجرسون يتابع الطلب.</small>
                        </div>
                    @endif
                </div>
            </div>
        </aside>

        <main class="menu-main">
            @if($featured->count())
                <div class="menu-section is-featured" id="cat-featured" x-data="sliderSection()" x-init="$nextTick(() => initSlider())">
                    <div class="cat-section">
                        <div class="cat-title">
                            <span class="bar"></span>
                            <span class="cat-title-text">
                                <i class="bi bi-star-fill"></i>
                                مميزة اليوم
                            </span>
                            <span class="count">{{ $featured->count() }}</span>
                            @if($featured->count() > 3)
                                <button type="button" class="view-toggle" @click="toggleMode()">
                                    <i class="bi" :class="mode === 'slider' ? 'bi-grid-3x3-gap' : 'bi-arrow-left-right'"></i>
                                    <span x-text="mode === 'slider' ? 'عرض الكل' : 'شريط'"></span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="menu-slider">
                        <button type="button" class="slider-arrow slider-arrow-prev" @click="slide(-1)" aria-label="السابق">‹</button>
                        <div class="slider-track">
                            @foreach($featured as $item)
                                @include('customer.partials.dish', ['item' => $item])
                            @endforeach
                        </div>
                        <button type="button" class="slider-arrow slider-arrow-next" @click="slide(1)" aria-label="التالي">›</button>
                    </div>
                </div>
            @endif

            @foreach($categories as $cat)
                <div class="menu-section" id="cat-{{ $cat->id }}" x-data="sliderSection()" x-init="$nextTick(() => initSlider())">
                    <div class="cat-section">
                        <div class="cat-title">
                            <span class="bar" @if($cat->color) style="background:{{ $cat->color }};" @endif></span>
                            <span class="cat-title-text">
                                <i class="bi {{ $cat->icon ?: 'bi-tag' }}"></i>
                                {{ $cat->name }}
                            </span>
                            <span class="count">{{ $cat->menuItems->count() }}</span>
                            @if($cat->menuItems->count() > 3)
                                <button type="button" class="view-toggle" @click="toggleMode()">
                                    <i class="bi" :class="mode === 'slider' ? 'bi-grid-3x3-gap' : 'bi-arrow-left-right'"></i>
                                    <span x-text="mode === 'slider' ? 'عرض الكل' : 'شريط'"></span>
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="menu-slider">
                        <button type="button" class="slider-arrow slider-arrow-prev" @click="slide(-1)" aria-label="السابق">‹</button>
                        <div class="slider-track">
                            @foreach($cat->menuItems as $item)
                                @include('customer.partials.dish', ['item' => $item])
                            @endforeach
                        </div>
                        <button type="button" class="slider-arrow slider-arrow-next" @click="slide(1)" aria-label="التالي">›</button>
                    </div>
                </div>
            @endforeach

            @if($categories->isEmpty())
                <div class="empty-state">
                    <i class="bi bi-journal-x"></i>
                    <p class="mt-3">لا توجد أصناف متاحة حالياً</p>
                </div>
            @endif

            <div class="menu-bottom-spacer"></div>
        </main>
    </div>

    {{-- Cart FAB --}}
    <button class="cart-fab" @click="openCart()" x-show="cartCount > 0" x-cloak id="cartFab">
        <span class="cart-fab-left">
            <span class="count" x-text="cartCount"></span>
            <span>راجع السلة</span>
        </span>
        <span class="cart-fab-right">
            <strong x-text="cartDisplayTotalFormatted"></strong>
            <i class="bi bi-arrow-left-short fs-4"></i>
        </span>
    </button>

    <div class="sheet-overlay" :class="sheetOpen ? 'open' : ''" @click="closeAll()"></div>

    {{-- Item sheet / modal --}}
    <div class="sheet sheet-item" :class="currentSheet === 'item' ? 'open' : ''" x-cloak>
        <div class="sheet-handle"></div>
        <template x-if="selectedItem">
            <div class="d-flex flex-column h-100" style="min-height: 0;">
                <div class="sheet-header item-sheet-header">
                    <h5>
                        <span x-text="selectedItem.name"></span>
                        <small>اختيارات الطبق والكمية</small>
                    </h5>
                    <button class="btn-close" @click="closeAll()" aria-label="إغلاق"></button>
                </div>
                <div class="sheet-body">
                    {{-- Image with price & featured tag --}}
                    <div class="item-img-wrap">
                        <img :src="selectedItem.image" :alt="selectedItem.name">
                        <span class="price-tag" x-text="formatMoney(selectedItem.price)"></span>
                    </div>

                    <p class="text-muted small mb-3" x-text="selectedItem.description" x-show="selectedItem.description"></p>

                    <template x-for="group in selectedItem.modifier_groups" :key="group.id">
                        <div class="mb-3">
                            <div class="section-label">
                                <span>
                                    <i class="bi bi-sliders2"></i>
                                    <span x-text="group.name"></span>
                                    <span class="badge bg-danger-subtle text-danger" x-show="group.required" style="font-size: .65rem;">مطلوب</span>
                                </span>
                                <span class="hint">
                                    اختر <span x-text="group.min_select === group.max_select ? group.min_select : group.min_select + '-' + group.max_select"></span>
                                </span>
                            </div>
                            <template x-for="mod in group.modifiers" :key="mod.id">
                                <label class="mod-opt">
                                    <span class="d-flex align-items-center gap-2">
                                        <input :type="group.max_select === 1 ? 'radio' : 'checkbox'"
                                            :name="'group_' + group.id"
                                            :value="mod.id"
                                            @change="toggleMod(group, mod, $event)">
                                        <span x-text="mod.name"></span>
                                    </span>
                                    <span class="price" x-show="mod.price_delta > 0" x-text="'+' + formatMoney(mod.price_delta)"></span>
                                    <span class="price text-danger" x-show="mod.price_delta < 0" x-text="formatMoney(mod.price_delta)"></span>
                                </label>
                            </template>
                        </div>
                    </template>

                    <div class="mb-3">
                        <div class="section-label">
                            <span><i class="bi bi-chat-text-fill"></i> ملاحظات خاصة</span>
                            <span class="hint">اختياري</span>
                        </div>
                        <textarea x-model="itemNotes" class="form-control" rows="2"
                            placeholder="مثلاً: قلّل الجبنة، كتّر البطاطا، بدون بصل..."></textarea>
                    </div>

                    <div class="qty-panel">
                        <span class="fw-bold" style="color: var(--brand-dark);">الكمية</span>
                        <div class="stepper">
                            <button type="button" @click="itemQty = Math.max(1, itemQty - 1)" :disabled="itemQty <= 1">−</button>
                            <input type="number" x-model.number="itemQty" min="1" max="20">
                            <button type="button" @click="itemQty = Math.min(20, itemQty + 1)">+</button>
                        </div>
                    </div>
                </div>
                <div class="sheet-footer item-sheet-footer">
                    <button class="btn-send d-flex justify-content-between align-items-center" @click="addToCart()">
                        <span><i class="bi bi-plus-circle-fill"></i> أضف للسلة</span>
                        <span x-text="formatMoney(computePrice())"></span>
                    </button>
                </div>
            </div>
        </template>
    </div>

    {{-- Cart sheet --}}
    <div class="sheet" :class="currentSheet === 'cart' ? 'open' : ''" x-cloak>
        <div class="sheet-handle"></div>
        <div class="d-flex flex-column h-100" style="min-height: 0;">
            <div class="sheet-header cart-sheet-header">
                <h5>
                    <span><i class="bi bi-basket-fill" style="color: var(--brand);"></i> سلّتك (<span x-text="cartCount"></span>)</span>
                    <small>راجع الطلب قبل إرساله للجرسون</small>
                </h5>
                <button class="btn-close" @click="closeAll()"></button>
            </div>
            <div class="sheet-body">
                <div x-show="cart.length === 0" class="empty-state">
                    <i class="bi bi-cart-x"></i>
                    <p class="mt-2">السلة فارغة</p>
                    <button class="btn-brand mt-2" @click="closeAll()" style="width: auto; padding: 10px 20px;">
                        <i class="bi bi-list"></i> تصفّح القائمة
                    </button>
                </div>

                {{-- Add more button at top --}}
                <button class="sheet-addmore" @click="closeAll()" x-show="cart.length > 0">
                    <i class="bi bi-plus-circle"></i> أضف المزيد من القائمة
                </button>

                <div class="cart-session-card" x-show="cart.length > 0">
                    <div>
                        <span>الطاولة</span>
                        <strong>{{ $tableNumber }}</strong>
                    </div>
                    @if($branchName)
                        <div>
                            <span>الفرع</span>
                            <strong>{{ $branchName }}</strong>
                        </div>
                    @endif
                    <div>
                        <span>الحالة</span>
                        <strong>بانتظار الإرسال</strong>
                    </div>
                </div>

                <div class="cart-flow-steps" x-show="cart.length > 0" aria-label="مسار الطلب">
                    <div class="cart-flow-step is-current">
                        <span><i class="bi bi-basket2-fill"></i></span>
                        <strong>السلة</strong>
                        <small>راجع الأصناف</small>
                    </div>
                    <div class="cart-flow-line"></div>
                    <div class="cart-flow-step">
                        <span><i class="bi bi-person-check-fill"></i></span>
                        <strong>الجرسون</strong>
                        <small>اعتماد سريع</small>
                    </div>
                    <div class="cart-flow-line"></div>
                    <div class="cart-flow-step">
                        <span><i class="bi bi-fire"></i></span>
                        <strong>المطبخ/البار</strong>
                        <small>حسب المحطة</small>
                    </div>
                </div>

                <template x-for="row in cart" :key="row.id">
                    <div class="cart-item">
                        <img :src="row.image" alt="">
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="d-flex justify-content-between align-items-start">
                                <strong class="d-block" x-text="row.name" style="color: var(--brand-dark);"></strong>
                                <strong style="color: var(--brand);" x-text="formatMoney(row.subtotal)"></strong>
                            </div>
                            <div class="mods" x-show="row.modifiers && row.modifiers.length" x-text="(row.modifiers || []).map(m => m.name).join('، ')"></div>

                            {{-- Editable per-item note --}}
                            <input type="text"
                                class="cart-item-notes"
                                :class="(row.notes || '').length > 0 ? 'has-value' : ''"
                                :value="row.notes || ''"
                                @change="updateNotes(row.id, $event.target.value)"
                                placeholder="ملاحظات هذا الصنف: قلل جبنة، بدون بصل..."
                                maxlength="200">

                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="stepper stepper-sm">
                                    <button type="button" @click="updateQty(row.id, Number(row.quantity) - 1)">−</button>
                                    <input type="number" :value="Number(row.quantity)" readonly>
                                    <button type="button" @click="updateQty(row.id, Number(row.quantity) + 1)">+</button>
                                </div>
                                <button type="button" class="btn btn-sm btn-link text-danger" @click="removeRow(row.id)">
                                    <i class="bi bi-trash3"></i> إزالة
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <div x-show="cart.length > 0" class="cart-checkout-panel">
                    <form action="{{ route('customer.cart.submit') }}" method="POST" id="submitForm">@csrf
                        @if($linkedCustomer)
                            {{-- Portal-authenticated OR cookie-remembered. The
                                 cashier-side link is already implicit via the
                                 session — we don't need to re-send the name
                                 unless we want it on this specific session
                                 row. Send phone as a hint so submit() can
                                 re-confirm the link if the cookie+session
                                 ever drift apart. --}}
                            <input type="hidden" name="customer_name" value="{{ $linkedCustomer->name }}">
                            @if($linkedCustomer->phone)
                                <input type="hidden" name="customer_phone" value="{{ $linkedCustomer->phone }}">
                            @endif
                            <div class="cart-customer-card is-linked">
                                <span class="cart-customer-avatar">{{ mb_substr($linkedCustomer->name, 0, 1, 'UTF-8') }}</span>
                                <div>
                                    <strong>أهلاً يا {{ $linkedCustomer->name }} 👋</strong>
                                    <small>
                                        @if($portalCustomer)
                                            بحسابك المسجّل{{ $portalCustomer->phone ? ' · '.$portalCustomer->phone : '' }} · الطلب رح ينحفظ في سجلك.
                                        @else
                                            تعرّفنا عليك من زيارتك السابقة · الطلب رح ينحفظ في سجلك.
                                        @endif
                                    </small>
                                </div>
                            </div>
                        @else
                            <div class="cart-customer-card">
                                <span class="cart-customer-avatar"><i class="bi bi-person"></i></span>
                                <div>
                                    <strong>تطلب كضيف</strong>
                                    <small>ما بنطلب تسجيل دخول عشان ترسل الطلب. الحساب فقط لحفظ الزيارة وسجل الطلبات.</small>
                                    <div class="cart-account-actions">
                                        <a href="{{ $portalLoginUrl }}">عندي حساب</a>
                                        <a href="{{ $portalRegisterUrl }}">إنشاء حساب</a>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold"><i class="bi bi-person-fill"></i> اسم على الطاولة (اختياري)</label>
                                <input name="customer_name" value="{{ $defaultCustomerName }}" class="form-control form-control-lg" placeholder="مثلاً: أحمد">
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-telephone-fill"></i> رقم الجوال (اختياري)
                                </label>
                                <input name="customer_phone" type="tel" inputmode="tel"
                                       value="{{ $session->customer_phone ?? '' }}"
                                       class="form-control form-control-lg"
                                       placeholder="مثلاً: 0599123456"
                                       autocomplete="tel">
                                <small class="text-muted d-block mt-1">
                                    <i class="bi bi-stars text-warning"></i>
                                    لو عندك حساب عنا، رح نتعرّف عليك تلقائياً ونحفظ الزيارة في سجلك.
                                </small>
                            </div>
                        @endif

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-bold"><i class="bi bi-people-fill"></i> عدد الأشخاص</label>
                                <input type="number" name="cover_count" min="1" max="20" value="{{ $session->cover_count ?? 1 }}" class="form-control form-control-lg">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label fw-bold"><i class="bi bi-chat-dots-fill"></i> ملاحظات عامة للجرسون</label>
                            <textarea name="notes" rows="2" class="form-control" placeholder="أي ملاحظة عامة للطاقم (اختياري)"></textarea>
                        </div>
                    </form>
                </div>
            </div>
            <div class="sheet-footer cart-footer" x-show="cart.length > 0">
                <div class="cart-total-line">
                    <div>
                        <div class="text-muted small" x-text="cartTotalLabel"></div>
                        <strong class="fs-4" style="color: var(--brand-dark);" x-text="cartDisplayTotalFormatted"></strong>
                        <div class="cart-tax-note" x-text="cartTaxNote"></div>
                    </div>
                    <div class="text-end small text-muted">
                        <div>الأصناف: <strong x-text="cartCount"></strong></div>
                    </div>
                </div>
                <div class="cart-total-breakdown" x-show="cart.length > 0">
                    <div>
                        <span>المجموع</span>
                        <strong x-text="cartTotalFormatted"></strong>
                    </div>
                    <div x-show="taxDisplayMode === 'inclusive' && cartTax > 0">
                        <span>الضريبة (<span x-text="taxRate"></span>%)</span>
                        <strong x-text="cartTaxFormatted"></strong>
                    </div>
                    <div x-show="taxDisplayMode === 'inclusive' && cartService > 0">
                        <span>الخدمة (<span x-text="serviceRate"></span>%)</span>
                        <strong x-text="cartServiceFormatted"></strong>
                    </div>
                </div>
                <button type="button" class="btn-send cart-submit-btn d-flex align-items-center justify-content-center gap-2" @click="askConfirm()">
                    <i class="bi bi-send-fill"></i> إرسال للجرسون
                </button>
            </div>
        </div>
    </div>

    {{-- Confirm submit dialog --}}
    <div class="confirm-overlay" :class="confirmOpen ? 'open' : ''" x-cloak @click.self="confirmOpen = false">
        <div class="confirm-box">
            <div class="emoji">🍽️</div>
            <h4>تأكيد إرسال الطلب؟</h4>
            <p class="text-muted mb-3">
                سيصل طلبك إلى الجرسون للمراجعة والاعتماد، وبعدها يتم توجيه الأصناف للمطبخ أو البار حسب المحطة.
            </p>
            <div class="p-3 mb-3 rounded" style="background: var(--brand-soft);">
                <div class="d-flex justify-content-between mb-1">
                    <span>عدد الأصناف:</span><strong x-text="cartCount"></strong>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span x-text="cartTotalLabel"></span><strong style="color: var(--brand-dark);" x-text="cartDisplayTotalFormatted"></strong>
                </div>
                <div class="small text-muted" x-text="cartTaxNote"></div>
                <div class="small text-muted" x-show="taxDisplayMode === 'inclusive' && cartTax > 0">
                    الضريبة: <span x-text="cartTaxFormatted"></span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-light flex-grow-1" @click="confirmOpen = false" :disabled="submitting" style="font-weight: 700;">تراجع</button>
                <button class="btn-send" style="flex: 2;" @click="confirmSubmit()" :disabled="submitting">
                    <template x-if="!submitting">
                        <span><i class="bi bi-check2-circle"></i> تأكيد الإرسال للجرسون</span>
                    </template>
                    <template x-if="submitting">
                        <span class="d-inline-flex align-items-center gap-2">
                            <span class="spinner-border spinner-border-sm" style="width:18px; height:18px;"></span>
                            جارٍ الإرسال...
                        </span>
                    </template>
                </button>
            </div>
        </div>
    </div>

</div>

@push('styles')
<style>
body {
    background:
        linear-gradient(180deg, #f4f8f5 0%, #fbf6ed 44%, #f7f3ea 100%);
}

html,
body,
body > main {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}

.app-topbar {
    width: 100%;
    max-width: 100%;
    position: sticky;
    top: 0;
    z-index: 60;
    overflow-x: hidden;
    background: var(--brand-gradient);
    color: #fff;
}

.qr-menu-page {
    --menu-surface: #fffdf7;
    --menu-card: #ffffff;
    --menu-ink: #14251c;
    --menu-muted: #68736b;
    --menu-line: rgba(33, 72, 52, .14);
    --menu-shadow: 0 18px 48px rgba(24, 58, 41, .11);
    width: min(100%, 1460px);
    margin: 0 auto;
    padding: clamp(.75rem, 2vw, 1.5rem);
    box-sizing: border-box;
    overflow-x: clip;
}

.menu-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(210px, 270px);
    gap: clamp(.75rem, 1.6vw, 1.15rem);
    align-items: stretch;
    border: 1px solid var(--menu-line);
    border-radius: 24px;
    background:
        linear-gradient(135deg, rgba(255,255,255,.96), rgba(242,248,242,.9)),
        var(--menu-surface);
    box-shadow: var(--menu-shadow);
    padding: clamp(.9rem, 2.1vw, 1.45rem);
    overflow: hidden;
    max-width: 100%;
}

.menu-hero-copy {
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.menu-brand-row {
    display: inline-flex;
    align-items: center;
    gap: .65rem;
    width: fit-content;
    max-width: 100%;
    margin-bottom: .65rem;
    padding: .38rem .75rem .38rem .45rem;
    border-radius: 999px;
    border: 1px solid rgba(31, 71, 51, .1);
    background: rgba(255,255,255,.78);
    box-shadow: 0 8px 20px rgba(24, 58, 41, .08);
}

.menu-brand-logo {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    background: #fff;
    padding: 4px;
    box-shadow: inset 0 0 0 1px rgba(31, 71, 51, .08);
}

.menu-brand-logo img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    border-radius: 10px;
}

.menu-brand-row strong {
    min-width: 0;
    color: var(--brand-dark);
    font-size: .95rem;
    font-weight: 900;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.menu-eyebrow,
.menu-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .55rem;
}

.menu-eyebrow {
    margin-bottom: .65rem;
}

.menu-eyebrow span,
.client-pill {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    border-radius: 999px;
    background: #edf6ee;
    color: var(--brand-dark);
    border: 1px solid rgba(31, 71, 51, .09);
    padding: .38rem .78rem;
    font-size: .78rem;
    font-weight: 900;
    line-height: 1;
    white-space: nowrap;
}

.menu-hero h1 {
    margin: 0;
    color: var(--brand-dark);
    font-size: clamp(2rem, 4.2vw, 3.35rem);
    font-weight: 900;
    line-height: 1.02;
    letter-spacing: 0;
    text-wrap: balance;
}

.menu-hero p {
    display: none;
}

.menu-hero-actions {
    margin-top: .9rem;
    align-items: center;
}

.hero-cart-btn {
    min-height: 48px;
    border: 0;
    border-radius: 999px;
    background: var(--brand);
    color: #fff;
    padding: 0 1.15rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .55rem;
    font-weight: 900;
    box-shadow: 0 12px 28px rgba(31, 71, 51, .28);
}

.hero-cart-btn:disabled {
    opacity: .68;
    box-shadow: none;
}

.client-pill.is-linked {
    background: #fff3d8;
    color: #795316;
    border-color: rgba(184, 135, 42, .22);
}

.menu-hero-panel {
    display: grid;
    gap: .75rem;
}

.hero-table-card {
    min-height: 135px;
    border-radius: 22px;
    background: linear-gradient(145deg, var(--brand), var(--brand-dark));
    color: #fff;
    display: grid;
    place-items: center;
    text-align: center;
    padding: 1rem;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.08);
}

.hero-table-card span {
    display: block;
    opacity: .78;
    font-weight: 900;
}

.hero-table-card strong {
    display: block;
    margin-top: .15rem;
    font-size: clamp(2.8rem, 7vw, 4.45rem);
    line-height: .95;
    font-weight: 900;
}

.hero-stat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .85rem;
}

.hero-stat {
    position: relative;
    display: flex;
    align-items: center;
    gap: .85rem;
    padding: 1rem 1.05rem;
    border-radius: 20px;
    background: linear-gradient(140deg, #ffffff 0%, #f5faf5 100%);
    border: 1px solid rgba(15, 71, 49, .08);
    box-shadow: 0 8px 22px rgba(15, 71, 49, .06);
    overflow: hidden;
    transition: transform .18s ease, box-shadow .18s ease;
}

.hero-stat::before {
    content: "";
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    width: 4px;
    background: linear-gradient(180deg, var(--brand), var(--brand-dark));
    border-start-end-radius: 4px;
    border-end-end-radius: 4px;
}

.hero-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 30px rgba(15, 71, 49, .1);
}

.hero-stat-icon {
    flex: 0 0 46px;
    width: 46px;
    height: 46px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    background: linear-gradient(140deg, rgba(15, 71, 49, .12), rgba(15, 71, 49, .04));
    color: var(--brand-dark);
    font-size: 1.25rem;
}

.hero-stat-icon--accent {
    background: linear-gradient(140deg, rgba(184, 135, 42, .18), rgba(184, 135, 42, .06));
    color: #8a6614;
}

.hero-stat-body {
    display: flex;
    flex-direction: column;
    gap: .15rem;
    min-width: 0;
    line-height: 1.15;
}

.hero-stat-label {
    color: var(--menu-muted);
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: 0;
}

.hero-stat-value {
    color: var(--brand-dark);
    font-size: 1.85rem;
    font-weight: 900;
    font-feature-settings: "tnum" 1;
    letter-spacing: -.5px;
}

.hero-stat-meta {
    color: var(--menu-muted);
    font-size: .72rem;
    font-weight: 700;
    opacity: .85;
}

@media (max-width: 480px) {
    .hero-stat {
        padding: .85rem;
        gap: .7rem;
    }

    .hero-stat-icon {
        flex-basis: 40px;
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }

    .hero-stat-value {
        font-size: 1.55rem;
    }
}

.menu-command-bar {
    position: sticky;
    top: calc(var(--topbar-h, 72px) + .45rem);
    z-index: 38;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: .5rem;
    margin: .75rem 0;
    padding: .5rem;
    border: 1px solid var(--menu-line);
    border-radius: 20px;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(16px);
    box-shadow: 0 12px 34px rgba(24, 58, 41, .09);
    max-width: 100%;
}

.menu-search {
    min-height: 40px;
    display: flex;
    align-items: center;
    gap: .65rem;
    border-radius: 13px;
    border: 1px solid rgba(33, 72, 52, .12);
    background: #f7f8f3;
    padding: 0 .75rem;
    color: var(--menu-muted);
}

.menu-search input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    color: var(--menu-ink);
    font: inherit;
    font-size: .92rem;
    font-weight: 800;
}

.menu-search input::placeholder {
    color: #777f78;
    font-size: .9rem;
    font-weight: 700;
}

.menu-search button {
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    background: #fff;
    color: var(--menu-muted);
}

.menu-command-bar .cat-tabs {
    position: relative;
    top: auto;
    display: block;
    padding: 0 2.25rem;
    border: 0;
    box-shadow: none;
    background: transparent;
}

.menu-command-bar .cat-tabs-scroll {
    padding: 0;
}

.menu-command-bar .tabs-arrow {
    width: 30px;
    height: 30px;
    font-size: 1.05rem;
    box-shadow: 0 3px 10px rgba(31, 71, 51, .18), 0 0 0 1px rgba(31,71,51,.06);
}

.menu-command-bar .tabs-arrow.slider-arrow-prev { inset-inline-start: 0; }
.menu-command-bar .tabs-arrow.slider-arrow-next { inset-inline-end: 0; }

.menu-command-bar .cat-tab {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 36px;
    padding: 0 .85rem;
    border-color: rgba(33, 72, 52, .13);
    background: #fff;
}

.menu-command-bar .cat-tab.active {
    background: var(--brand);
    border-color: var(--brand);
    color: #fff;
}

.menu-layout-modern {
    display: grid;
    gap: 1rem;
    width: 100%;
    max-width: none;
    padding: 0;
    align-items: start;
    max-width: 100%;
    overflow-x: clip;
}

.menu-layout-modern .menu-main {
    min-width: 0;
}

.menu-layout-modern .menu-aside {
    display: none;
}

.menu-layout-modern .side-tabs {
    border-radius: 20px;
    box-shadow: 0 12px 30px rgba(24, 58, 41, .08);
}

.menu-client-card {
    margin: .85rem .65rem .4rem;
    display: flex;
    gap: .7rem;
    align-items: center;
    padding: .85rem;
    border-radius: 16px;
    border: 1px solid rgba(184, 135, 42, .18);
    background: #fffaf0;
}

.menu-client-avatar {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--brand);
    color: #fff;
    font-weight: 900;
}

.menu-client-card strong,
.menu-client-card small {
    display: block;
}

.menu-client-card strong {
    color: var(--brand-dark);
    font-weight: 900;
}

.menu-client-card small {
    margin-top: .15rem;
    color: var(--menu-muted);
    line-height: 1.55;
}

.menu-section {
    scroll-margin-top: calc(var(--topbar-h, 72px) + 96px);
    margin-bottom: 1rem;
    border: 1px solid rgba(33, 72, 52, .1);
    border-radius: 22px;
    background: rgba(255,255,255,.66);
    box-shadow: 0 10px 30px rgba(24, 58, 41, .06);
    overflow: hidden;
    max-width: 100%;
}

.cat-tabs-scroll,
.slider-track {
    max-width: 100%;
}

.menu-section.is-featured {
    border-color: rgba(184, 135, 42, .22);
    background: linear-gradient(180deg, rgba(255,250,240,.92), rgba(255,255,255,.72));
}

.cat-section {
    padding: .95rem 1rem .35rem;
}

.cat-title {
    margin: 0;
    min-width: 0;
    color: var(--brand-dark);
}

.cat-title-text {
    min-width: 0;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
}

.cat-title .count {
    background: #edf6ee;
    color: var(--brand-dark);
}

.view-toggle {
    margin-right: auto;
    flex: 0 0 auto;
    background: #fff;
}

.qr-menu-page .menu-slider {
    padding-top: .2rem;
}

.qr-menu-page .menu-grid {
    padding: .85rem;
}

.qr-menu-page .dish {
    border-radius: 18px;
    border: 1px solid rgba(33, 72, 52, .08);
    box-shadow: 0 10px 22px rgba(24, 58, 41, .07);
}

.qr-menu-page .dish:hover {
    box-shadow: 0 16px 32px rgba(24, 58, 41, .13);
}

.qr-menu-page .dish-img {
    aspect-ratio: 4 / 3;
    background: #eef2ea;
}

.qr-menu-page .dish-body {
    padding: .85rem;
}

.qr-menu-page .dish-name {
    font-size: 1rem;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.qr-menu-page .dish-desc {
    min-height: 2.1em;
}

.qr-menu-page .dish-price {
    color: var(--brand-dark);
}

.qr-menu-page .dish-add-fab,
.qr-menu-page .dish-stepper {
    box-shadow: 0 8px 16px rgba(31, 71, 51, .24);
}

.qr-menu-page .dish-add-fab {
    width: auto;
    min-width: 70px;
    height: 38px;
    border-radius: 999px;
    padding: 0 .7rem;
    gap: .35rem;
    font-size: .82rem;
}

.qr-menu-page .dish-add-fab i {
    font-size: .9rem;
}

.qr-menu-page .dish-add-fab span {
    display: inline-block;
    line-height: 1;
}

.item-sheet-header h5,
.cart-sheet-header h5 {
    display: flex;
    flex-direction: column;
    gap: .15rem;
}

.item-sheet-header h5 small,
.cart-sheet-header h5 small {
    color: var(--menu-muted);
    font-size: .74rem;
    font-weight: 700;
}

.qty-panel {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .8rem;
    margin-top: .95rem;
    padding: .85rem;
    border-radius: 16px;
    border: 1px solid rgba(31, 71, 51, .11);
    background: #edf6ee;
}

.item-sheet-footer,
.cart-footer {
    background: linear-gradient(180deg, #fff, #f8faf7);
    box-shadow: 0 -12px 30px rgba(24, 58, 41, .08);
}

.qr-menu-page .cart-item {
    padding: .75rem;
    margin-bottom: .7rem;
    border: 1px solid rgba(31, 71, 51, .09);
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 8px 18px rgba(24, 58, 41, .05);
}

.qr-menu-page .cart-item:last-child {
    border-bottom: 1px solid rgba(31, 71, 51, .09);
}

.qr-menu-page .cart-item img {
    width: 64px;
    height: 64px;
    border-radius: 14px;
}

.qr-menu-page .cart-item-notes {
    background: #fffaf0;
    border-color: rgba(184, 135, 42, .35);
}

.cart-checkout-panel {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 18px;
    border: 1px solid rgba(31, 71, 51, .1);
    background: #f8faf7;
}

.cart-session-card {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: .55rem;
    margin-bottom: .8rem;
}

.cart-session-card div {
    min-width: 0;
    padding: .7rem;
    border-radius: 14px;
    border: 1px solid rgba(31, 71, 51, .1);
    background: #f8faf7;
}

.cart-session-card span,
.cart-session-card strong {
    display: block;
}

.cart-session-card span {
    color: var(--menu-muted);
    font-size: .72rem;
    font-weight: 800;
}

.cart-session-card strong {
    margin-top: .1rem;
    color: var(--brand-dark);
    font-size: .9rem;
    font-weight: 900;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cart-flow-steps {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 24px minmax(0, 1fr) 24px minmax(0, 1fr);
    align-items: center;
    gap: .42rem;
    margin: .15rem 0 .85rem;
    padding: .7rem;
    border: 1px solid rgba(31, 71, 51, .1);
    border-radius: 16px;
    background: #ffffff;
}

.cart-flow-step {
    min-width: 0;
    display: grid;
    justify-items: center;
    gap: .18rem;
    text-align: center;
}

.cart-flow-step span {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #edf6ee;
    color: var(--brand);
    border: 1px solid rgba(31, 71, 51, .1);
}

.cart-flow-step.is-current span {
    background: var(--brand);
    color: #fff;
}

.cart-flow-step strong {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--brand-dark);
    font-size: .78rem;
    font-weight: 900;
}

.cart-flow-step small {
    color: var(--menu-muted);
    font-size: .68rem;
    font-weight: 800;
    line-height: 1.25;
}

.cart-flow-line {
    height: 2px;
    border-radius: 999px;
    background: linear-gradient(90deg, rgba(31, 71, 51, .2), rgba(184, 135, 42, .35));
}

.cart-customer-card {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    margin-bottom: .95rem;
    padding: .85rem;
    border-radius: 16px;
    border: 1px solid rgba(31, 71, 51, .1);
    background: #ffffff;
}

.cart-customer-card.is-linked {
    background: #f2f8f2;
    border-color: rgba(31, 71, 51, .16);
}

.cart-customer-avatar {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--brand);
    color: #fff;
    font-weight: 900;
}

.cart-customer-card strong,
.cart-customer-card small {
    display: block;
}

.cart-customer-card strong {
    color: var(--brand-dark);
    font-weight: 900;
}

.cart-customer-card small {
    margin-top: .18rem;
    color: var(--menu-muted);
    line-height: 1.55;
    font-weight: 700;
}

.cart-account-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .45rem;
    margin-top: .55rem;
}

.cart-account-actions a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 0 .72rem;
    border-radius: 999px;
    background: #edf6ee;
    color: var(--brand-dark);
    border: 1px solid rgba(31, 71, 51, .1);
    text-decoration: none;
    font-size: .78rem;
    font-weight: 900;
}

.cart-account-actions a:last-child {
    background: #fff3d8;
    color: #795316;
    border-color: rgba(184, 135, 42, .2);
}

.cart-checkout-panel .form-label {
    margin-bottom: .35rem;
    color: var(--brand-dark);
    font-size: .84rem;
}

.cart-checkout-panel .form-control,
.cart-checkout-panel .form-control-lg {
    min-height: 44px;
    border-radius: 14px;
    font-size: .95rem;
}

.cart-total-line {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    margin-bottom: .85rem;
    padding-bottom: .85rem;
    border-bottom: 1px solid var(--border);
}

.cart-tax-note {
    margin-top: .15rem;
    color: var(--menu-muted);
    font-size: .76rem;
    font-weight: 800;
}

.cart-total-breakdown {
    display: grid;
    gap: .28rem;
    margin: -.35rem 0 .85rem;
    padding: .7rem .85rem;
    border-radius: 14px;
    background: rgba(237, 246, 238, .75);
    border: 1px solid rgba(31, 71, 51, .08);
}

.cart-total-breakdown div {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    color: var(--menu-muted);
    font-size: .8rem;
    font-weight: 800;
}

.cart-total-breakdown strong {
    color: var(--brand-dark);
}

.cart-submit-btn {
    min-height: 52px;
}

.linked-account-hint {
    margin-top: .45rem;
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    color: #6b4a12;
    background: #fff3d8;
    border: 1px solid rgba(184, 135, 42, .18);
    border-radius: 999px;
    padding: .35rem .7rem;
    font-size: .8rem;
    font-weight: 800;
}

.menu-bottom-spacer {
    height: 130px;
}

@media (min-width: 768px) {
    .qr-menu-page .dish-img {
        aspect-ratio: 16 / 11;
    }
}

@media (min-width: 992px) {
    .menu-command-bar {
        grid-template-columns: minmax(280px, 430px) minmax(0, 1fr);
        align-items: center;
    }

    .menu-layout-modern {
        grid-template-columns: 270px minmax(0, 1fr);
    }

    .menu-layout-modern .menu-aside {
        display: block;
        position: sticky;
        top: calc(var(--topbar-h, 72px) + 94px);
        max-height: calc(100vh - var(--topbar-h, 72px) - 110px);
        overflow: auto;
    }

    .qr-menu-page .menu-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (min-width: 1280px) {
    .menu-layout-modern {
        grid-template-columns: 290px minmax(0, 1fr);
    }

    .qr-menu-page .menu-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 991.98px) {
    .qr-menu-page {
        padding: .75rem;
    }

    .menu-hero {
        grid-template-columns: 1fr;
        border-radius: 22px;
    }

    .menu-hero-panel {
        grid-template-columns: minmax(120px, 150px) minmax(0, 1fr);
        align-items: stretch;
    }

    .hero-table-card {
        min-height: 110px;
    }

    .hero-stat-grid {
        grid-template-columns: 1fr;
    }

    .menu-command-bar {
        top: calc(var(--topbar-h, 72px) + .25rem);
        margin-inline: -.15rem;
    }
}

@media (max-width: 575.98px) {
    .qr-menu-page {
        padding: .6rem;
    }

    .menu-hero {
        padding: 1rem;
        border-radius: 20px;
    }

    .menu-hero h1 {
        font-size: 2rem;
    }

    .menu-brand-row {
        gap: .5rem;
        margin-bottom: .7rem;
        padding: .32rem .65rem .32rem .38rem;
    }

    .menu-brand-logo {
        width: 34px;
        height: 34px;
        flex-basis: 34px;
        border-radius: 11px;
    }

    .menu-hero p {
        display: none;
    }

    .menu-hero-panel {
        grid-template-columns: 1fr;
    }

    .hero-table-card {
        min-height: 100px;
    }

    .hero-stat-grid {
        grid-template-columns: 1fr 1fr;
    }

    .menu-command-bar {
        border-radius: 18px;
        padding: .45rem;
    }

    .menu-search {
        min-height: 38px;
    }

    .menu-command-bar .cat-tab {
        min-height: 34px;
        padding: 0 .75rem;
        font-size: .78rem;
    }

    .cat-section {
        padding: .85rem .75rem .25rem;
    }

    .cat-title {
        font-size: 1rem;
        gap: .4rem;
    }

    .view-toggle {
        padding: 4px 9px;
        font-size: .7rem;
    }

    .qr-menu-page .menu-slider {
        padding-inline: 2.1rem;
    }

    .slider-track > .dish {
        flex-basis: 72%;
    }

    .qr-menu-page .menu-grid {
        grid-template-columns: 1fr 1fr;
        gap: .55rem;
        padding: .6rem;
    }

    .qr-menu-page .dish {
        border-radius: 16px;
    }

    .qr-menu-page .dish-body {
        padding: .7rem;
    }

    .qr-menu-page .dish-name {
        font-size: .9rem;
    }

    .qr-menu-page .dish-desc {
        display: none;
    }

    .qr-menu-page .dish-price {
        font-size: .95rem;
    }

    .qr-menu-page .dish-add-fab {
        width: auto;
        min-width: 62px;
        height: 34px;
        padding: 0 .58rem;
        font-size: .76rem;
    }

    .linked-account-hint {
        border-radius: 12px;
        align-items: flex-start;
        white-space: normal;
        line-height: 1.5;
    }

    .cart-session-card {
        grid-template-columns: 1fr;
    }

    .cart-customer-card {
        padding: .75rem;
    }
}
</style>
@endpush

@push('scripts')
<script>
function menuApp() {
    return {
        cart: @json(array_values($cart)),
        cardNotes: {},
        search: '',
        sheetOpen: false,
        currentSheet: null,
        selectedItem: null,
        selectedMods: [],
        itemNotes: '',
        itemQty: 1,
        activeCat: null,
        confirmOpen: false,
        submitting: false,
        currency: @json($currencySymbol),
        taxDisplayMode: @json($customerTaxDisplay),
        taxEnabled: @json($taxEnabled),
        taxRate: @json($taxRate),
        serviceEnabled: @json($serviceEnabled),
        serviceRate: @json($serviceRate),

        get cartCount() { return this.cart.reduce((s, r) => s + Number(r.quantity), 0); },
        get cartTotal() { return this.cart.reduce((s, r) => s + Number(r.subtotal), 0); },
        get cartTotalFormatted() { return this.formatMoney(this.cartTotal); },
        get cartTax() { return this.taxEnabled ? this.roundMoney(this.cartTotal * (Number(this.taxRate) / 100)) : 0; },
        get cartService() { return this.serviceEnabled ? this.roundMoney(this.cartTotal * (Number(this.serviceRate) / 100)) : 0; },
        get cartDisplayTotal() {
            if (this.taxDisplayMode !== 'inclusive') return this.cartTotal;
            return this.roundMoney(this.cartTotal + this.cartTax + this.cartService);
        },
        get cartDisplayTotalFormatted() { return this.formatMoney(this.cartDisplayTotal); },
        get cartTaxFormatted() { return this.formatMoney(this.cartTax); },
        get cartServiceFormatted() { return this.formatMoney(this.cartService); },
        get cartTotalLabel() {
            return this.taxDisplayMode === 'inclusive' ? 'الإجمالي شامل الضريبة' : 'الإجمالي قبل الضريبة';
        },
        get cartTaxNote() {
            const hasService = this.serviceEnabled && Number(this.serviceRate) > 0;
            if (! this.taxEnabled || Number(this.taxRate) <= 0) {
                if (! hasService) return 'لا توجد ضريبة مفعلة على السلة.';
                return this.taxDisplayMode === 'inclusive'
                    ? `يشمل خدمة ${this.serviceRate}% حسب إعدادات الفرع.`
                    : `الخدمة ${this.serviceRate}% تظهر في الفاتورة بعد اعتماد الطلب.`;
            }
            const servicePart = this.serviceEnabled && Number(this.serviceRate) > 0 ? ` والخدمة ${this.serviceRate}%` : '';
            if (this.taxDisplayMode === 'inclusive') return `يشمل ضريبة ${this.taxRate}%${servicePart} حسب إعدادات الفرع.`;
            return `الضريبة ${this.taxRate}%${servicePart} تظهر في الفاتورة بعد اعتماد الطلب.`;
        },

        roundMoney(n) { return Math.round((Number(n) || 0) * 100) / 100; },
        formatMoney(n) { return (Number(n) || 0).toFixed(2) + ' ' + this.currency; },

        matchesSearch(value) {
            const q = (this.search || '').trim().toLowerCase();
            if (! q) return true;
            return String(value || '').toLowerCase().includes(q);
        },

        // Sum of all cart rows belonging to a specific menu_item_id
        qtyOf(itemId) {
            return this.cart.filter(r => Number(r.menu_item_id) === Number(itemId))
                .reduce((s, r) => s + Number(r.quantity), 0);
        },

        // Handle + button click — simple items add directly, complex open modal
        onPlus(itemData) {
            try {
                if (! itemData || ! itemData.id) {
                    console.error('[Menu] onPlus called with invalid itemData:', itemData);
                    showToast('خطأ في بيانات الصنف — حدّث الصفحة', 'danger');
                    return;
                }
                if (itemData.has_modifiers) {
                    this.openItem(itemData);
                    return;
                }
                this.quickAdd(itemData);
            } catch (e) {
                console.error('[Menu] onPlus threw:', e, 'item:', itemData);
                showToast('حدث خطأ — راجع console للتفاصيل', 'danger');
            }
        },

        // − OPTIMISTIC: decrement immediately, sync to server in background.
        // Previously this awaited the server before updating UI, so on slow
        // networks the − button felt broken ("one tap works, the next doesn't"
        // because the cart state was still in-flight).
        async onMinus(itemData) {
            const rows = this.cart.filter(r => Number(r.menu_item_id) === Number(itemData.id));
            if (rows.length === 0) return;
            const last = rows[rows.length - 1];
            const oldQty = Number(last.quantity);
            const newQty = oldQty - 1;

            if (newQty <= 0) {
                // Optimistic remove
                const removedRow = { ...last };
                this.cart = this.cart.filter(r => r.id !== last.id);
                try {
                    const fd = new FormData();
                    fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
                    fd.append('row_id', last.id);
                    const res = await fetch(@json(route('customer.cart.remove')), {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: fd,
                        credentials: 'same-origin',
                    });
                    if (! res.ok && ! res.redirected) {
                        this.cart.push(removedRow);  // rollback
                        showToast('تعذّر إزالة الصنف', 'danger');
                    }
                } catch (e) {
                    this.cart.push(removedRow);  // rollback
                    showToast('خطأ اتصال', 'danger');
                }
                return;
            }

            // Optimistic decrement
            last.quantity = newQty;
            last.subtotal = (Number(last.unit_price) + Number(last.modifiers_total)) * newQty;
            try {
                const fd = new FormData();
                fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
                fd.append('row_id', last.id);
                fd.append('quantity', newQty);
                const res = await fetch(@json(route('customer.cart.update')), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: fd,
                    credentials: 'same-origin',
                });
                if (! res.ok && ! res.redirected) {
                    last.quantity = oldQty;  // rollback
                    last.subtotal = (Number(last.unit_price) + Number(last.modifiers_total)) * oldQty;
                    showToast('تعذّر تحديث الكمية', 'danger');
                }
            } catch (e) {
                last.quantity = oldQty;  // rollback
                last.subtotal = (Number(last.unit_price) + Number(last.modifiers_total)) * oldQty;
                showToast('خطأ اتصال', 'danger');
            }
        },

        // Tap on card body opens details/notes. The visible add button remains
        // the fast path for simple items.
        onCardClick(itemData, ev) {
            this.openItem(itemData);
        },

        // Quick add — OPTIMISTIC: UI flips to stepper IMMEDIATELY, server
        // sync happens in background. On failure, we roll back.
        async quickAdd(itemData) {
            const notes = (this.cardNotes[itemData.id] || '').trim();
            const sourceImg = document.querySelector(`[data-dish-img="${itemData.id}"]`);

            /* ── CASE A: this item already has a simple row in cart → bump qty ── */
            const existing = this.cart.find(r =>
                Number(r.menu_item_id) === Number(itemData.id)
                && (r.modifiers || []).length === 0
                && (r.notes || '') === notes
            );
            if (existing) {
                // Optimistic bump
                const oldQty = Number(existing.quantity);
                const newQty = oldQty + 1;
                existing.quantity = newQty;
                existing.subtotal = (Number(existing.unit_price) + Number(existing.modifiers_total)) * newQty;
                this.flyToCart(sourceImg, itemData.image, itemData.name);

                // Sync in background
                try {
                    const fd = new FormData();
                    fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
                    fd.append('row_id', existing.id);
                    fd.append('quantity', newQty);
                    const res = await fetch(@json(route('customer.cart.update')), {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: fd,
                        credentials: 'same-origin',
                    });
                    if (! res.ok && ! res.redirected) {
                        // Rollback
                        existing.quantity = oldQty;
                        existing.subtotal = (Number(existing.unit_price) + Number(existing.modifiers_total)) * oldQty;
                        showToast('تعذّر تحديث الكمية', 'danger');
                    }
                } catch (e) {
                    existing.quantity = oldQty;
                    existing.subtotal = (Number(existing.unit_price) + Number(existing.modifiers_total)) * oldQty;
                    showToast('خطأ اتصال — حاول مجدداً', 'danger');
                }
                return;
            }

            /* ── CASE B: new row — push to cart INSTANTLY, then verify with server ── */
            const tmpId = 'tmp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
            const optimisticRow = {
                id: tmpId,
                menu_item_id: itemData.id,
                name: itemData.name,
                image: itemData.image,
                quantity: 1,
                unit_price: Number(itemData.price),
                modifiers: [],
                modifiers_total: 0,
                subtotal: Number(itemData.price),
                notes: notes,
                _pending: true,   // visual hint (dimmed) while server confirms
            };
            this.cart.push(optimisticRow);
            this.flyToCart(sourceImg, itemData.image, itemData.name);

            // Background sync
            const fd = new FormData();
            fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
            fd.append('menu_item_id', itemData.id);
            fd.append('quantity', 1);
            if (notes) fd.append('notes', notes);

            try {
                const res = await fetch(@json(route('customer.cart.add')), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: fd,
                    credentials: 'same-origin',
                });
                if (res.status === 419) {
                    this.cart = this.cart.filter(r => r.id !== tmpId);
                    this.handleSessionExpired();
                    return;
                }
                if (res.ok) {
                    // Replace tmp id with server's real row id so future
                    // updates/removes match on the server side.
                    try {
                        const data = await res.json();
                        if (data && data.row && data.row.id) {
                            optimisticRow.id = data.row.id;
                        }
                    } catch (_) { /* non-JSON response is fine too */ }
                    optimisticRow._pending = false;
                } else if (res.redirected) {
                    // Non-AJAX redirect — server accepted but gave us HTML back.
                    // Tmp id stays; not ideal but the add succeeded.
                    optimisticRow._pending = false;
                } else {
                    // Rollback on failure
                    this.cart = this.cart.filter(r => r.id !== tmpId);
                    let msg = 'تعذّر إضافة الصنف';
                    if (res.status === 422) msg = 'الصنف غير متوفر';
                    else if (res.status === 403) msg = 'لا يُسمح بهذا الصنف الآن';
                    console.warn('[Cart] add failed', res.status, itemData.name);
                    showToast(msg, 'danger');
                }
            } catch (e) {
                this.cart = this.cart.filter(r => r.id !== tmpId);
                console.error('[Cart] fetch error:', e);
                showToast('خطأ اتصال — حاول مجدداً', 'danger');
            }
        },

        handleSessionExpired() {
            showToast('انتهت الجلسة — يرجى مسح QR الطاولة من جديد', 'warning');
            setTimeout(() => { window.location.href = '/'; }, 2500);
        },

        // Persist note change on the latest row for a given simple item (if exists)
        async syncNotesFor(itemId) {
            const notes = (this.cardNotes[itemId] || '').trim();
            const rows = this.cart.filter(r =>
                Number(r.menu_item_id) === Number(itemId)
                && (r.modifiers || []).length === 0
            );
            // Update local state only (server only knows notes at add-time for simple flow).
            // For better UX, the note applies to next add for that item.
            rows.forEach(r => { if (! r.notes) r.notes = notes; });
        },

        openItem(itemData) {
            this.selectedItem = itemData;
            this.selectedMods = [];
            // Pre-fill notes from inline card field, if any
            this.itemNotes = (this.cardNotes[itemData.id] || '').trim();
            this.itemQty = 1;
            (itemData.modifier_groups || []).forEach(g => {
                if (g.required && g.max_select === 1 && g.modifiers.length) {
                    this.selectedMods.push({ group_id: g.id, id: g.modifiers[0].id, price_delta: Number(g.modifiers[0].price_delta) });
                }
            });
            this.currentSheet = 'item';
            this.sheetOpen = true;
            setTimeout(() => {
                (itemData.modifier_groups || []).forEach(g => {
                    if (g.required && g.max_select === 1 && g.modifiers.length) {
                        const r = document.querySelector(`input[name="group_${g.id}"][value="${g.modifiers[0].id}"]`);
                        if (r) r.checked = true;
                    }
                });
            }, 30);
        },

        openCart() {
            // Capture FAB position to animate sheet "expanding" from it
            const fab = document.getElementById('cartFab');
            if (fab) {
                const r = fab.getBoundingClientRect();
                document.documentElement.style.setProperty('--sheet-origin-x', `${r.left + r.width/2}px`);
                document.documentElement.style.setProperty('--sheet-origin-y', `${r.top + r.height/2}px`);
            }
            this.currentSheet = 'cart';
            this.sheetOpen = true;
        },
        closeAll() { this.sheetOpen = false; this.currentSheet = null; this.selectedItem = null; },

        toggleMod(group, mod, ev) {
            const gId = group.id, mId = mod.id, pd = Number(mod.price_delta);
            if (group.max_select === 1) {
                this.selectedMods = this.selectedMods.filter(x => x.group_id !== gId);
                if (ev.target.checked) this.selectedMods.push({ group_id: gId, id: mId, price_delta: pd });
            } else {
                const idx = this.selectedMods.findIndex(x => x.id === mId);
                if (ev.target.checked) {
                    if (idx < 0) this.selectedMods.push({ group_id: gId, id: mId, price_delta: pd });
                } else if (idx >= 0) this.selectedMods.splice(idx, 1);
            }
        },

        computePrice() {
            if (! this.selectedItem) return 0;
            const base = Number(this.selectedItem.price);
            const mods = this.selectedMods.reduce((s, m) => s + Number(m.price_delta), 0);
            return (base + mods) * this.itemQty;
        },

        async addToCart() {
            // Client-side validation first
            for (const g of (this.selectedItem.modifier_groups || [])) {
                const picked = this.selectedMods.filter(x => x.group_id === g.id).length;
                if (g.required && picked < g.min_select) {
                    alert(`يجب اختيار ${g.min_select} على الأقل من ${g.name}`);
                    return;
                }
                if (picked > g.max_select) {
                    alert(`الحد الأقصى ${g.max_select} من ${g.name}`);
                    return;
                }
            }

            const addedImage = this.selectedItem.image;
            const addedName = this.selectedItem.name;
            const addedId   = this.selectedItem.id;
            const sourceImg = document.querySelector(`[data-dish-img="${addedId}"]`);

            // Optimistic: push row to cart + close modal + fly animation — ALL IMMEDIATELY
            const tmpId = 'tmp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
            const optimisticRow = {
                id: tmpId,
                menu_item_id: addedId,
                name: addedName,
                image: addedImage,
                quantity: this.itemQty,
                unit_price: Number(this.selectedItem.price),
                modifiers: this.selectedMods.map(m => ({
                    id: m.id,
                    name: (this.selectedItem.modifier_groups.flatMap(g => g.modifiers).find(mm => mm.id === m.id) || {}).name,
                    price_delta: m.price_delta,
                })),
                modifiers_total: this.selectedMods.reduce((s, m) => s + m.price_delta, 0),
                subtotal: this.computePrice(),
                notes: this.itemNotes,
                _pending: true,
            };
            this.cart.push(optimisticRow);
            this.closeAll();
            setTimeout(() => this.flyToCart(sourceImg, addedImage, addedName), 100);

            // Sync with server
            const fd = new FormData();
            fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
            fd.append('menu_item_id', addedId);
            fd.append('quantity', optimisticRow.quantity);
            fd.append('notes', optimisticRow.notes || '');
            this.selectedMods.forEach(m => fd.append('modifier_ids[]', m.id));

            try {
                const res = await fetch(@json(route('customer.cart.add')), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: fd,
                    credentials: 'same-origin',
                });
                if (res.status === 419) {
                    this.cart = this.cart.filter(r => r.id !== tmpId);
                    this.handleSessionExpired();
                    return;
                }
                if (res.ok) {
                    try {
                        const data = await res.json();
                        if (data && data.row && data.row.id) optimisticRow.id = data.row.id;
                    } catch (_) {}
                    optimisticRow._pending = false;
                } else if (res.redirected) {
                    optimisticRow._pending = false;
                } else {
                    // Rollback
                    this.cart = this.cart.filter(r => r.id !== tmpId);
                    showToast('تعذّر إضافة الصنف — حاول مجدداً', 'danger');
                }
            } catch (e) {
                this.cart = this.cart.filter(r => r.id !== tmpId);
                console.error('[Cart] modifier add failed:', e);
                showToast('خطأ اتصال', 'danger');
            }
        },

        flyToCart(sourceEl, imageUrl, name) {
            const target = document.getElementById('cartFab');
            if (! target) { showToast(name + ' أُضيف للسلة ✓', 'success'); return; }
            const targetRect = target.getBoundingClientRect();

            const startRect = sourceEl ? sourceEl.getBoundingClientRect() : { top: window.innerHeight / 2, left: window.innerWidth / 2, width: 60, height: 60 };

            const ghost = document.createElement('div');
            ghost.className = 'fly-ghost';
            ghost.style.backgroundImage = `url('${imageUrl}')`;
            ghost.style.top = (startRect.top + (startRect.height / 2) - 30) + 'px';
            ghost.style.left = (startRect.left + (startRect.width / 2) - 30) + 'px';
            document.body.appendChild(ghost);

            requestAnimationFrame(() => {
                ghost.style.top = (targetRect.top + (targetRect.height / 2) - 30) + 'px';
                ghost.style.left = (targetRect.left + (targetRect.width / 2) - 30) + 'px';
                ghost.style.transform = 'scale(.2)';
                ghost.style.opacity = '0';
            });

            setTimeout(() => {
                ghost.remove();
                // Bump/shake the cart FAB
                target.classList.remove('bump');
                void target.offsetWidth;  // force reflow so animation retriggers
                target.classList.add('bump');
                setTimeout(() => target.classList.remove('bump'), 600);
                showToast(name + ' أُضيف للسلة ✓', 'success');
            }, 720);
        },

        async updateQty(rowId, qty) {
            // Force numeric — defends against `"1" + 1 = "11"` bug if any caller
            // passes a string from an `<input>` or JSON payload.
            qty = Number(qty);
            if (!Number.isFinite(qty) || qty < 1) return this.removeRow(rowId);

            const fd = new FormData();
            fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
            fd.append('row_id', rowId);
            fd.append('quantity', qty);
            await fetch(@json(route('customer.cart.update')), { method: 'POST', body: fd, credentials: 'same-origin' });
            const row = this.cart.find(r => r.id === rowId);
            if (row) {
                row.quantity = qty;
                row.subtotal = (Number(row.unit_price) + Number(row.modifiers_total)) * qty;
            }
        },

        async updateNotes(rowId, notes) {
            const trimmed = (notes || '').trim();
            const fd = new FormData();
            fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
            fd.append('row_id', rowId);
            fd.append('notes', trimmed);
            try {
                await fetch(@json(route('customer.cart.update')), { method: 'POST', body: fd, credentials: 'same-origin' });
                const row = this.cart.find(r => r.id === rowId);
                if (row) row.notes = trimmed;
                showToast('تم حفظ الملاحظة ✓', 'success');
            } catch (e) {}
        },

        async removeRow(rowId) {
            const fd = new FormData();
            fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
            fd.append('row_id', rowId);
            await fetch(@json(route('customer.cart.remove')), { method: 'POST', body: fd, credentials: 'same-origin' });
            this.cart = this.cart.filter(r => r.id !== rowId);
        },

        askConfirm() {
            if (this.cart.length === 0) return;
            this.confirmOpen = true;
        },

        confirmSubmit() {
            if (this.submitting) return;  // prevent double-click
            this.submitting = true;
            document.getElementById('submitForm').submit();
        },

        scrollTo(catId) {
            this.activeCat = catId;
            const el = document.getElementById('cat-' + catId);
            if (el) {
                const topbarH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-h')) || 80;
                const offset = topbarH + 8;
                const y = el.getBoundingClientRect().top + window.scrollY - offset;
                window.scrollTo({ top: y, behavior: 'smooth' });
            }
        },

        // Observe sections to auto-highlight active tab while scrolling
        initScrollSpy() {
            const sections = document.querySelectorAll('[id^="cat-"]');
            if (! sections.length || ! window.IntersectionObserver) return;
            const self = this;
            const topbarH = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-h')) || 80;
            const obs = new IntersectionObserver((entries) => {
                entries.forEach(e => {
                    if (e.isIntersecting) {
                        const id = e.target.id.replace('cat-', '');
                        self.activeCat = id;
                    }
                });
            }, { rootMargin: `-${topbarH + 16}px 0px -60% 0px`, threshold: 0 });
            sections.forEach(s => obs.observe(s));
        },
    };
}
document.addEventListener('alpine:initialized', () => {
    const root = document.querySelector('[x-data="menuApp()"]');
    if (root && root._x_dataStack && root._x_dataStack[0].initScrollSpy) {
        root._x_dataStack[0].initScrollSpy();
    }
});

/* Cart FAB scroll-aware visibility.
   When the user is scrolling DOWN more than a few px per tick they're
   browsing the menu — hide the FAB so it can't cover the + button of the
   dish beneath it (with pointer-events:none while hidden). When they scroll
   UP or stop, bring it back. A short idle timer ensures the FAB reappears
   even if the last scroll direction was down. Uses requestAnimationFrame
   to coalesce scroll events — no perf hit. */
(function () {
    let lastY = window.scrollY;
    let ticking = false;
    let idleTimer = null;
    const THRESHOLD = 8;      // ignore jitter under this many px
    const IDLE_MS   = 700;    // reappear after this long with no scroll

    function onScroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => {
            const fab = document.getElementById('cartFab');
            const y   = window.scrollY;
            const dy  = y - lastY;

            if (fab) {
                // Only hide if the FAB is actually showing (cart not empty
                // → Alpine added x-show true). Don't fight Alpine's display.
                const visible = getComputedStyle(fab).display !== 'none';
                if (visible) {
                    if (dy > THRESHOLD && y > 120) {
                        fab.classList.add('is-hidden');
                    } else if (dy < -THRESHOLD) {
                        fab.classList.remove('is-hidden');
                    }
                }
            }

            lastY = y;
            ticking = false;

            // Reappear after user stops scrolling
            clearTimeout(idleTimer);
            idleTimer = setTimeout(() => {
                const f = document.getElementById('cartFab');
                if (f) f.classList.remove('is-hidden');
            }, IDLE_MS);
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
})();

// Horizontal scroll for the category tabs strip — shows prev/next arrows
// only when the strip can scroll, and scrolls by ~70% of the visible width
// per click. Uses scrollLeft sign-agnostic math so it works in RTL too.
function tabsSlider() {
    return {
        canPrev: false,
        canNext: false,
        init() {
            this.update();
            window.addEventListener('resize', () => this.update(), { passive: true });
        },
        update() {
            const t = this.$refs.scroll;
            if (! t) return;
            const sl = Math.abs(t.scrollLeft);
            const maxScroll = t.scrollWidth - t.clientWidth;
            this.canPrev = sl > 4;
            this.canNext = sl < maxScroll - 4;
        },
        slide(direction) {
            const t = this.$refs.scroll;
            if (! t) return;
            const isRtl = getComputedStyle(t).direction === 'rtl';
            const delta = t.clientWidth * 0.7 * (isRtl ? -direction : direction);
            t.scrollBy({ left: delta, behavior: 'smooth' });
            setTimeout(() => this.update(), 350);
        },
    };
}

// Per-category slider Alpine component.
//
// Scrolls the TRACK ELEMENT ONLY. We deliberately do NOT use scrollIntoView
// because on a partially-visible slider it also scrolls the page vertically,
// which made the arrows look like they were doing nothing.
//
// RTL handling: modern browsers (Chrome 85+, Firefox, Safari 16+) normalize
// RTL scroll so scrollLeft goes 0 → −maxScroll. We detect direction per-call
// and flip the delta so `scrollBy({left})` moves the track the way the user
// expects regardless of LTR/RTL.
function sliderSection() {
    return {
        mode: 'slider',
        // IMPORTANT: use `this.$root` (stable component root), NOT `this.$el`.
        // Alpine's `$el` is the element currently evaluating the directive —
        // so inside a method called by @click on the arrow BUTTON, `$el` is
        // the button itself, not the .menu-section. That caused
        // `this.$el.querySelector('.slider-track')` to return null and the
        // arrows to silently do nothing. `$root` is stable: it's always the
        // x-data component root.
        toggleMode() {
            this.mode = this.mode === 'slider' ? 'grid' : 'slider';
            this.$root.classList.toggle('grid-mode', this.mode === 'grid');
            if (this.mode === 'slider') this.$nextTick(() => this.updateArrows());
        },
        // direction: -1 = previous card · +1 = next card.
        // Uses viewport-relative delta + scrollBy — works identically in
        // LTR and RTL because scrollBy just adds to scrollLeft and our
        // signed delta naturally points the right way in each direction.
        slide(direction) {
            const track = this.$root.querySelector('.slider-track');
            if (! track) return;
            const cards = Array.from(track.querySelectorAll(':scope > .dish'));
            if (! cards.length) return;

            const isRtl = getComputedStyle(track).direction === 'rtl';
            const trackRect = track.getBoundingClientRect();
            const startEdge = isRtl ? trackRect.right : trackRect.left;

            // 1. Find the card whose start edge is currently closest to the
            //    track's start edge (the "featured" card right now).
            let currentIdx = 0;
            let best = Infinity;
            cards.forEach((c, i) => {
                const r = c.getBoundingClientRect();
                const cardStart = isRtl ? r.right : r.left;
                const d = Math.abs(cardStart - startEdge);
                if (d < best) { best = d; currentIdx = i; }
            });

            const targetIdx = Math.max(0, Math.min(cards.length - 1, currentIdx + direction));
            if (targetIdx === currentIdx) { this.updateArrows(); return; }

            // 2. Compute the px delta and scroll the track (not the page).
            const targetRect = cards[targetIdx].getBoundingClientRect();
            const targetStart = isRtl ? targetRect.right : targetRect.left;
            const deltaPx = targetStart - startEdge;

            track.scrollBy({ left: deltaPx, behavior: 'smooth' });
            setTimeout(() => this.updateArrows(), 400);
        },
        updateArrows() {
            const track = this.$root.querySelector('.slider-track');
            if (! track) return;
            const prev = this.$root.querySelector('.slider-arrow-prev');
            const next = this.$root.querySelector('.slider-arrow-next');
            if (! prev || ! next) return;

            const maxScroll = track.scrollWidth - track.clientWidth;
            const sl = Math.abs(track.scrollLeft);
            const scrollable = maxScroll > 4;
            prev.style.display = scrollable ? '' : 'none';
            next.style.display = scrollable ? '' : 'none';
            prev.disabled = sl < 5;
            next.disabled = sl > maxScroll - 5;
        },
        initSlider() {
            const track = this.$root.querySelector('.slider-track');
            if (! track) return;
            this.$root.classList.toggle('grid-mode', this.mode === 'grid');
            this.updateArrows();
            track.addEventListener('scroll', () => this.updateArrows(), { passive: true });
            window.addEventListener('resize', () => {
                this.updateArrows();
            });
        },
    };
}
</script>
@endpush
@endsection
