@extends('customer.layout')
@section('title','القائمة')
@section('content')

<div x-data="menuApp()" @keydown.escape.window="closeAll()">

    {{-- Hero / Welcome --}}
    <div class="hero">
        <div class="welcome-emoji">👋</div>
        <h2>أهلاً بك{{ $session->customer_name ? '، '.$session->customer_name : '' }} في {{ config('restaurant.name') }}</h2>
        <p class="subtitle">تصفّح القائمة واطلب براحتك — نحن في خدمتك</p>
        <div style="height: 3px; width: 50px; background: var(--accent); margin: .75rem auto 0; border-radius: 2px;"></div>
    </div>

    {{-- Category tabs — horizontal for mobile (sticky under topbar) --}}
    <div class="cat-tabs">
        <div class="cat-tabs-scroll" id="catScroll">
            @if($featured->count())
                <a href="#cat-featured" class="cat-tab" :class="activeCat === 'featured' ? 'active' : ''"
                   @click.prevent="scrollTo('featured')">⭐ مميزة اليوم</a>
            @endif
            @foreach($categories as $cat)
                <a href="#cat-{{ $cat->id }}" class="cat-tab" :class="activeCat === '{{ $cat->id }}' ? 'active' : ''"
                   @click.prevent="scrollTo('{{ $cat->id }}')">{{ $cat->name }}</a>
            @endforeach
        </div>
    </div>

    {{-- Layout: main + side (desktop only) --}}
    <div class="menu-layout">
        <main class="menu-main">
            {{-- Featured --}}
            @if($featured->count())
                <div class="menu-section" id="cat-featured" x-data="sliderSection()">
                    <div class="cat-section">
                        <div class="cat-title">
                            <span class="bar"></span>
                            <i class="bi bi-star-fill" style="color: var(--accent);"></i> مميزة اليوم
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
                    <div class="menu-grid">
                        @foreach($featured as $item)
                            @include('customer.partials.dish', ['item' => $item])
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Categories --}}
            @foreach($categories as $cat)
                <div class="menu-section" id="cat-{{ $cat->id }}" x-data="sliderSection()">
                    <div class="cat-section">
                        <div class="cat-title">
                            <span class="bar" @if($cat->color) style="background:{{ $cat->color }};" @endif></span>
                            <i class="bi {{ $cat->icon ?: 'bi-tag' }}"></i>
                            {{ $cat->name }}
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
                    <div class="menu-grid">
                        @foreach($cat->menuItems as $item)
                            @include('customer.partials.dish', ['item' => $item])
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if($categories->isEmpty())
                <div class="empty-state"><i class="bi bi-journal-x"></i><p class="mt-3">لا توجد أصناف متاحة حالياً</p></div>
            @endif

            <div style="height: 120px;"></div>
        </main>

        {{-- Desktop vertical sidebar — appears on left in RTL due to DOM order --}}
        <aside class="menu-aside">
            <div class="side-tabs">
                <div class="side-tabs-title">
                    قائمة الأقسام
                    <span class="title-sub">{{ $categories->count() + ($featured->count() ? 1 : 0) }} قسم</span>
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

                {{-- Tip box removed — it added visual noise without a clear action.
                     Categories list speaks for itself. --}}
            </div>
        </aside>
    </div>

    {{-- Cart FAB --}}
    <button class="cart-fab" @click="openCart()" x-show="cartCount > 0" x-cloak id="cartFab">
        <span class="cart-fab-left">
            <span class="count" x-text="cartCount"></span>
            <span>عرض السلة</span>
        </span>
        <span class="cart-fab-right">
            <strong x-text="cartTotalFormatted"></strong>
            <i class="bi bi-arrow-left-short fs-4"></i>
        </span>
    </button>

    <div class="sheet-overlay" :class="sheetOpen ? 'open' : ''" @click="closeAll()"></div>

    {{-- Item sheet / modal --}}
    <div class="sheet" :class="currentSheet === 'item' ? 'open' : ''" x-cloak>
        <div class="sheet-handle"></div>
        <template x-if="selectedItem">
            <div class="d-flex flex-column h-100" style="min-height: 0;">
                <div class="sheet-header">
                    <h5 x-text="selectedItem.name"></h5>
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

                    <div class="d-flex justify-content-between align-items-center mt-3 p-3 rounded" style="background: var(--brand-soft);">
                        <span class="fw-bold" style="color: var(--brand-dark);">الكمية</span>
                        <div class="stepper">
                            <button type="button" @click="itemQty = Math.max(1, itemQty - 1)" :disabled="itemQty <= 1">−</button>
                            <input type="number" x-model.number="itemQty" min="1" max="20">
                            <button type="button" @click="itemQty = Math.min(20, itemQty + 1)">+</button>
                        </div>
                    </div>
                </div>
                <div class="sheet-footer">
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
            <div class="sheet-header">
                <h5><i class="bi bi-basket-fill" style="color: var(--brand);"></i> سلّتك (<span x-text="cartCount"></span>)</h5>
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

                <div x-show="cart.length > 0" class="mt-4 pt-3 border-top">
                    <form action="{{ route('customer.cart.submit') }}" method="POST" id="submitForm">@csrf
                        <div class="mb-2">
                            <label class="form-label fw-bold"><i class="bi bi-person-fill"></i> اسمك (اختياري)</label>
                            <input name="customer_name" value="{{ $session->customer_name }}" class="form-control form-control-lg" placeholder="مثلاً: أحمد">
                        </div>
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
            <div class="sheet-footer" x-show="cart.length > 0" style="background: linear-gradient(180deg, #fff, #f9fafb);">
                <div class="d-flex justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <div class="text-muted small">الإجمالي (بدون ضريبة)</div>
                        <strong class="fs-4" style="color: var(--brand-dark);" x-text="cartTotalFormatted"></strong>
                    </div>
                    <div class="text-end small text-muted">
                        <div>الأصناف: <strong x-text="cartCount"></strong></div>
                    </div>
                </div>
                <button type="button" class="btn-send d-flex align-items-center justify-content-center gap-2" @click="askConfirm()">
                    <i class="bi bi-send-fill"></i> أرسل الطلب
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
                سيصل طلبك إلى الجرسون الذي سيعتمده. بعد الاعتماد لن تتمكن من إلغائه بضغطة واحدة.
            </p>
            <div class="p-3 mb-3 rounded" style="background: var(--brand-soft);">
                <div class="d-flex justify-content-between mb-1">
                    <span>عدد الأصناف:</span><strong x-text="cartCount"></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>الإجمالي:</span><strong style="color: var(--brand-dark);" x-text="cartTotalFormatted"></strong>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-light flex-grow-1" @click="confirmOpen = false" :disabled="submitting" style="font-weight: 700;">تراجع</button>
                <button class="btn-send" style="flex: 2;" @click="confirmSubmit()" :disabled="submitting">
                    <template x-if="!submitting">
                        <span><i class="bi bi-check2-circle"></i> تأكيد الإرسال</span>
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

@push('scripts')
<script>
function menuApp() {
    return {
        cart: @json(array_values($cart)),
        cardNotes: {},
        sheetOpen: false,
        currentSheet: null,
        selectedItem: null,
        selectedMods: [],
        itemNotes: '',
        itemQty: 1,
        activeCat: null,
        confirmOpen: false,
        submitting: false,
        currency: @json(config('restaurant.currency_symbol')),

        get cartCount() { return this.cart.reduce((s, r) => s + Number(r.quantity), 0); },
        get cartTotal() { return this.cart.reduce((s, r) => s + Number(r.subtotal), 0); },
        get cartTotalFormatted() { return this.formatMoney(this.cartTotal); },

        formatMoney(n) { return (Number(n) || 0).toFixed(2) + ' ' + this.currency; },

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

        // - decrements the most recent row for this item; if 0, removes the row
        async onMinus(itemData) {
            const rows = this.cart.filter(r => Number(r.menu_item_id) === Number(itemData.id));
            if (rows.length === 0) return;
            const last = rows[rows.length - 1];
            const newQty = Number(last.quantity) - 1;
            if (newQty <= 0) {
                await this.removeRow(last.id);
            } else {
                await this.updateQty(last.id, newQty);
            }
        },

        // Tap on card body (not buttons) → open modal only if has modifiers, else no-op
        onCardClick(itemData, ev) {
            // Ignore clicks from interactive children handled via @click.stop
            if (itemData.has_modifiers) this.openItem(itemData);
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

// Per-category slider Alpine component
function sliderSection() {
    return {
        mode: 'slider', // or 'grid'
        toggleMode() {
            this.mode = this.mode === 'slider' ? 'grid' : 'slider';
            this.$el.classList.toggle('grid-mode', this.mode === 'grid');
        },
        slide(direction) {
            /* scrollBy is the safest cross-browser approach for RTL sliders.
               In CSSOM View spec (all modern browsers), `scrollBy({left: X})`
               uses LOGICAL coordinates: positive X = toward END of content
               regardless of LTR/RTL. Direction maps 1:1 with scrollBy.left. */
            const track = this.$el.querySelector('.slider-track');
            if (! track) return;
            const card = track.querySelector('.dish');
            if (! card) return;
            const step = (card.offsetWidth + 11) * 2;       // 2 cards

            const before = track.scrollLeft;
            track.scrollBy({ left: direction * step, behavior: 'smooth' });

            // Diagnostic — visible only in devtools console
            console.log('[slider] dir=' + direction,
                'scrollLeft before=' + before,
                'target delta=' + (direction * step),
                'track.scrollWidth=' + track.scrollWidth,
                'clientWidth=' + track.clientWidth);
        },
    };
}
</script>
@endpush
@endsection
