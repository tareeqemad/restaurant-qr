@extends('layouts.admin')
@section('title', 'طلب جديد — طاولة '.$table->number)

@section('content')
<x-admin.breadcrumb
    :title="'طلب جديد — طاولة '.$table->number"
    icon="bi-clipboard-plus"
    :crumbs="[['label' => 'الطاولات', 'url' => route('admin.waiter-orders.index')]]" />

<div class="row g-3">
    {{-- ─── LEFT: Menu (categories + items) ──────────────────────── --}}
    <div class="col-lg-8">
        {{-- Staff meal panel — shown ABOVE customer panel so a waiter
             taking a quick "for the manager" order doesn't accidentally
             attach the manager as a paying customer. Either mode is
             active at a time, never both. --}}
        @if($eligibleStaff->isNotEmpty())
            @php
                $staffActive = ! is_null($staffMember);
                $staffSummary = $staffActive
                    ? app(\App\Services\StaffMealService::class)->monthSummary($staffMember)
                    : null;
            @endphp
            <div class="card mb-3 {{ $staffActive ? 'border-warning' : '' }}"
                 x-data="{ open: {{ $staffActive ? 'true' : 'false' }} }">
                <div class="card-header d-flex justify-content-between align-items-center" @click="open = !open" style="cursor: pointer;">
                    <div>
                        <i class="bi bi-cup-hot-fill text-accent"></i>
                        <strong>طلب موظف (بدل الوجبات)</strong>
                        @if($staffActive)
                            <span class="badge bg-warning text-dark ms-2">
                                مفعّل: {{ $staffMember->name }}
                            </span>
                        @else
                            <span class="text-muted small">— غير مفعّل</span>
                        @endif
                    </div>
                    <i class="bi" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                </div>
                <div class="card-body" x-show="open" x-cloak>
                    @if($staffActive)
                        <div class="alert alert-warning small mb-2">
                            <strong>{{ $staffMember->name }}</strong> —
                            استهلك هذا الشهر: <strong>{{ \App\Helpers\Money::format($staffSummary['used']) }}</strong>
                            من <strong>{{ \App\Helpers\Money::format($staffSummary['allowance']) }}</strong>
                            (متبقي: <strong class="{{ $staffSummary['remaining'] < 0 ? 'text-danger' : 'text-success' }}">
                                {{ \App\Helpers\Money::format($staffSummary['remaining']) }}
                            </strong>)
                            @if($staffSummary['overflow'] > 0)
                                <br>
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong class="text-danger">تجاوز الحد بـ {{ \App\Helpers\Money::format($staffSummary['overflow']) }}</strong>
                                — هذا الطلب سيُضاف لدينه الشخصي.
                            @endif
                        </div>
                        <form action="{{ route('admin.waiter-orders.staff_mode', $session) }}" method="POST" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-x-lg"></i> إلغاء وضع طلب موظف
                            </button>
                        </form>
                    @else
                        <form action="{{ route('admin.waiter-orders.staff_mode', $session) }}" method="POST" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-8">
                                <label class="form-label small text-muted">اختر الموظف</label>
                                <select name="staff_user_id" class="form-select" required>
                                    <option value="">— اختر —</option>
                                    @foreach($eligibleStaff as $s)
                                        <option value="{{ $s->id }}">
                                            {{ $s->name }} (حد: {{ number_format((float) $s->monthly_meal_allowance, 0) }} ش.إ/شهر)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-warning w-100">
                                    <i class="bi bi-cup-hot"></i> تفعيل
                                </button>
                            </div>
                            <div class="col-12">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i>
                                    عند التفعيل، الطلب سيُحتسب على بدل وجبات الموظف بدلاً من إصدار فاتورة عادية.
                                    التجاوز يُسجَّل كدين شخصي عليه.
                                </small>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        {{-- Customer attach panel — collapsible to keep the menu's first
             impression compact. The waiter only opens it when they
             actually want to link a phone. --}}
        <div class="card mb-3" x-data="{ open: {{ $session->customer_id ? 'true' : 'false' }} }">
            <div class="card-header d-flex justify-content-between align-items-center" @click="open = !open" style="cursor: pointer;">
                <div>
                    <i class="bi bi-person-circle"></i>
                    <strong>الزبون</strong>
                    @if($session->customer_id)
                        <span class="badge bg-success ms-2">
                            مرتبط: {{ $session->customer_name ?? '#'.$session->customer_id }}
                        </span>
                    @else
                        <span class="text-muted small">— غير محدد</span>
                    @endif
                </div>
                <i class="bi" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
            </div>
            <div class="card-body" x-show="open" x-cloak>
                @if($session->customer_id)
                    <div class="mb-2 small">
                        <strong>{{ $session->customer_name }}</strong> ·
                        <span dir="ltr">{{ $session->customer_phone }}</span>
                    </div>
                    <form action="{{ route('admin.waiter-orders.customer.link', $session) }}" method="POST" class="d-inline">
                        @csrf
                        <input type="hidden" name="detach" value="1">
                        <button class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-x-lg"></i> إلغاء الربط
                        </button>
                    </form>
                @else
                    <form action="{{ route('admin.waiter-orders.customer.link', $session) }}" method="POST" class="row g-2 align-items-end">
                        @csrf
                        <div class="col-md-5">
                            <label class="form-label small text-muted">رقم الهاتف</label>
                            <input type="text" name="phone" class="form-control" dir="ltr"
                                   placeholder="0599…" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted">الاسم (إن كان جديداً)</label>
                            <input type="text" name="name" class="form-control"
                                   placeholder="مثلاً: محمد">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check mb-2">
                                <input type="hidden" name="create_if_missing" value="0">
                                <input type="checkbox" id="create_if_missing" name="create_if_missing"
                                       value="1" class="form-check-input" checked>
                                <label for="create_if_missing" class="form-check-label small">
                                    أضف الزبون إن لم يوجد
                                </label>
                            </div>
                            <button class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-link-45deg"></i> ربط
                            </button>
                        </div>
                    </form>
                    <small class="text-muted d-block mt-2">
                        <i class="bi bi-info-circle"></i>
                        اربط الزبون عشان نسجّل ديونه/نقاط الولاء وتاريخ طلباته. اختياري.
                    </small>
                @endif
            </div>
        </div>

        {{-- Category accordion. Each item shows price + stock status +
             quick "إضافة" button. Items with modifiers open a modal so
             the waiter can pick size/addons before submitting. --}}
        @forelse($categories as $cat)
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <strong>{{ $cat->name }}</strong>
                    <span class="badge bg-secondary ms-1">{{ $cat->menuItems->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @foreach($cat->menuItems as $item)
                        @php
                            $shortages = $item->stockShortages(1.0);
                            $inStock   = empty($shortages);
                            $hasMods   = $item->modifierGroups->count() > 0;
                        @endphp
                        <div class="d-flex align-items-center justify-content-between p-3 border-bottom {{ $inStock ? '' : 'bg-light' }}">
                            <div class="flex-grow-1">
                                <div class="fw-bold">
                                    {{ $item->name }}
                                    @if(! $inStock)
                                        <span class="badge bg-warning text-dark ms-1"
                                              title="{{ collect($shortages)->map(fn($s)=>$s['ingredient'].' (متاح '.rtrim(rtrim(number_format($s['available'],2),'0'),'.').')')->join('، ') }}">
                                            <i class="bi bi-box-seam"></i> نفد
                                        </span>
                                    @endif
                                    @if($hasMods)
                                        <span class="badge bg-info ms-1">
                                            <i class="bi bi-sliders2"></i> خيارات
                                        </span>
                                    @endif
                                </div>
                                @if($item->description)
                                    <small class="text-muted d-block">{{ \Illuminate\Support\Str::limit($item->description, 80) }}</small>
                                @endif
                            </div>
                            <div class="text-end ms-3">
                                @php
                                    // Promotion-aware price for the waiter. If a live
                                    // promo is active, show the discounted price + the
                                    // strikethrough so the floor staff know what the
                                    // customer is actually being charged.
                                    $waiterPromo  = $item->activePromotion();
                                    $waiterPrice  = $waiterPromo ? $waiterPromo->applyTo((float) $item->price) : (float) $item->price;
                                @endphp
                                @if($waiterPromo)
                                    <div class="small text-muted text-decoration-line-through">{{ \App\Helpers\Money::format($item->price) }}</div>
                                    <div class="fw-bold text-danger mb-1" title="{{ $waiterPromo->name }}">
                                        <i class="bi bi-tag-fill"></i>
                                        {{ \App\Helpers\Money::format($waiterPrice) }}
                                    </div>
                                @else
                                    <div class="fw-bold text-primary mb-1">{{ \App\Helpers\Money::format($item->price) }}</div>
                                @endif
                                @if($inStock)
                                    @if($hasMods)
                                        {{-- Modifier modal trigger --}}
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modItem{{ $item->id }}">
                                            <i class="bi bi-plus-lg"></i> إضافة
                                        </button>
                                    @else
                                        {{-- One-click add --}}
                                        <form action="{{ route('admin.waiter-orders.items.add', $session) }}" method="POST" class="d-inline">
                                            @csrf
                                            <input type="hidden" name="menu_item_id" value="{{ $item->id }}">
                                            <input type="hidden" name="quantity" value="1">
                                            <button class="btn btn-sm btn-primary">
                                                <i class="bi bi-plus-lg"></i> إضافة
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    <button class="btn btn-sm btn-light" disabled>نفد</button>
                                @endif
                            </div>
                        </div>

                        {{-- Modifier modal — rendered once per item with mods --}}
                        @if($hasMods && $inStock)
                            <div class="modal fade" id="modItem{{ $item->id }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form action="{{ route('admin.waiter-orders.items.add', $session) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="menu_item_id" value="{{ $item->id }}">
                                            <div class="modal-header">
                                                <h5 class="modal-title">{{ $item->name }}</h5>
                                                <button class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-2">
                                                    <label class="form-label">الكمية</label>
                                                    <input type="number" name="quantity" value="1" min="1" max="20" class="form-control" required>
                                                </div>
                                                @foreach($item->modifierGroups as $group)
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold">
                                                            {{ $group->name }}
                                                            @if($group->required) <span class="text-danger">*</span> @endif
                                                        </label>
                                                        @foreach($group->modifiers as $mod)
                                                            <div class="form-check">
                                                                <input type="{{ $group->max_select == 1 ? 'radio' : 'checkbox' }}"
                                                                       name="modifier_ids[]"
                                                                       value="{{ $mod->id }}"
                                                                       id="m{{ $item->id }}_{{ $mod->id }}"
                                                                       class="form-check-input">
                                                                <label for="m{{ $item->id }}_{{ $mod->id }}" class="form-check-label">
                                                                    {{ $mod->name }}
                                                                    @if($mod->price_delta != 0)
                                                                        <small class="text-muted">({{ $mod->price_delta > 0 ? '+' : '' }}{{ \App\Helpers\Money::format($mod->price_delta) }})</small>
                                                                    @endif
                                                                </label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endforeach
                                                <div class="mb-2">
                                                    <label class="form-label">ملاحظات (اختياري)</label>
                                                    <input type="text" name="notes" class="form-control" placeholder="مثلاً: بدون بصل">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                                                <button class="btn btn-primary">
                                                    <i class="bi bi-plus-lg"></i> إضافة للطلب
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @empty
            <div class="alert alert-warning">لا توجد أصناف متاحة في القائمة حالياً.</div>
        @endforelse
    </div>

    {{-- ─── RIGHT: Cart sidebar ─────────────────────────────────── --}}
    <div class="col-lg-4">
        <div class="card sticky-top" style="top: 1rem;">
            <div class="card-header bg-primary text-white">
                <strong><i class="bi bi-cart3"></i> سلة الطلب</strong>
                <span class="badge bg-light text-dark ms-1">{{ count($cart) }}</span>
            </div>
            <div class="card-body p-0">
                @if(count($cart))
                    <ul class="list-group list-group-flush">
                        @foreach($cart as $row)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">
                                            {{ $row['quantity'] }}× {{ $row['name'] }}
                                        </div>
                                        @if(! empty($row['modifiers']))
                                            <small class="text-muted d-block">
                                                {{ collect($row['modifiers'])->pluck('name')->join('، ') }}
                                            </small>
                                        @endif
                                        @if(! empty($row['notes']))
                                            <small class="text-info d-block">
                                                <i class="bi bi-sticky"></i> {{ $row['notes'] }}
                                            </small>
                                        @endif
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold">{{ \App\Helpers\Money::format($row['subtotal']) }}</div>
                                        <form action="{{ route('admin.waiter-orders.items.remove', $session) }}" method="POST" class="d-inline">
                                            @csrf @method('DELETE')
                                            <input type="hidden" name="row_id" value="{{ $row['id'] }}">
                                            <button class="btn btn-sm btn-link text-danger p-0" title="حذف">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @php $total = collect($cart)->sum('subtotal'); @endphp
                    <div class="p-3 border-top bg-light">
                        <div class="d-flex justify-content-between mb-2">
                            <strong>المجموع:</strong>
                            <strong class="fs-5 text-primary">{{ \App\Helpers\Money::format($total) }}</strong>
                        </div>
                        <small class="text-muted d-block mb-2">
                            <i class="bi bi-info-circle"></i>
                            الضريبة والخدمة تُحسب عند إرسال الطلب حسب الإعدادات.
                        </small>

                        <form action="{{ route('admin.waiter-orders.submit', $session) }}" method="POST">
                            @csrf
                            <div class="mb-2">
                                <input type="text" name="notes" class="form-control form-control-sm"
                                       placeholder="ملاحظة عامة (اختياري)">
                            </div>
                            <button class="btn btn-success w-100"
                                    onclick="return confirm('إرسال الطلب لطاولة {{ $table->number }}؟');">
                                <i class="bi bi-send-check"></i>
                                إرسال الطلب
                            </button>
                        </form>
                    </div>
                @else
                    <div class="text-center text-muted p-4">
                        <i class="bi bi-cart-x fs-2 d-block mb-2"></i>
                        <p class="mb-0 small">السلة فاضية — اختر أصناف من القائمة</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bank-transfer claim — visible once the session has at least
             one submitted order. The customer paid from their banking
             app and showed the waiter; the cashier confirms later in
             the bank's own dashboard. --}}
        @php
            $sessionOrdersCount = $session->orders()->count();
            $sessionTotalGuess = $session->orders()->sum('total');
        @endphp
        @if($sessionOrdersCount > 0)
            <div class="card mb-3 border-info">
                <div class="card-header bg-info-transparent">
                    <i class="bi bi-bank text-info"></i>
                    <strong>دفع بتحويل بنكي</strong>
                </div>
                <div class="card-body">
                    <small class="text-muted d-block mb-2">
                        الزبون حوّل المبلغ على حساب المطعم من تطبيق البنك.
                        أدخل التفاصيل وسيظهر للكاشير للتأكد من البنك.
                    </small>
                    <button type="button" class="btn btn-info w-100"
                            data-bs-toggle="modal" data-bs-target="#transferModal">
                        <i class="bi bi-cash-coin"></i> تسجيل تحويل
                    </button>
                </div>
            </div>

            <div class="modal fade" id="transferModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form action="{{ route('admin.waiter-orders.transfer.store', $session) }}" method="POST">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="bi bi-bank"></i> تسجيل تحويل — طاولة {{ $table->number }}
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        المبلغ المحوّل <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" step="0.01" min="0.01" name="amount"
                                           class="form-control" required
                                           value="{{ number_format((float) $sessionTotalGuess, 2, '.', '') }}">
                                    <small class="text-muted">
                                        المجموع الحالي للجلسة كاقتراح — عدّله إذا الزبون حوّل مبلغ مختلف.
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        اسم المحوّل (كما يظهر في كشف البنك) <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="sender_name" class="form-control" required maxlength="120"
                                           placeholder="مثلاً: محمد علي">
                                    <small class="text-muted">
                                        قد يكون الزبون أو شخص حوّل بدلاً منه (والد، صديق…).
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">رقم جوال الزبون (لو زبون دائم)</label>
                                    <input type="text" name="customer_phone" class="form-control" dir="ltr"
                                           maxlength="32" placeholder="0599…"
                                           value="{{ $session->customer_phone }}">
                                    <small class="text-muted">
                                        اختياري — لو موجود في قاعدة الزبائن، يظهر اسمه للكاشير.
                                    </small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">اسم الزبون (لو walk-in)</label>
                                    <input type="text" name="customer_name" class="form-control" maxlength="120"
                                           value="{{ $session->customer_name }}"
                                           placeholder="اسم الزبون لو مش مسجل بقاعدة البيانات">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">ملاحظات</label>
                                    <textarea name="notes" rows="2" class="form-control" maxlength="500"
                                              placeholder="اختياري — أي تفصيل يساعد الكاشير"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                                <button class="btn btn-info">
                                    <i class="bi bi-send-check"></i> أرسل للكاشير للتأكيد
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
