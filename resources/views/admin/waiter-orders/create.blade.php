@extends('layouts.admin')
@section('title', 'طلب جديد — طاولة '.$table->number)

{{-- POS styles in the <head> so the tile grid + cart paint styled on the
     first render — no flash of unstyled content (a component-body <style>
     applied late). --}}
@push('styles')
    <link rel="stylesheet"
          href="{{ asset('assets/dashtic/css/waiter-pos.css') }}?v={{ filemtime(public_path('assets/dashtic/css/waiter-pos.css')) }}">
@endpush

@section('content')
<x-admin.breadcrumb
    :title="'طلب جديد — طاولة '.$table->number"
    icon="bi-clipboard-plus"
    :crumbs="[['label' => 'الطاولات', 'url' => route('admin.waiter-orders.index')]]" />

{{-- ─── Carry-over guard ──────────────────────────────────────────────
     A previous party may still owe on this open session. Warn before the
     waiter stacks a new order on someone else's unpaid bill. --}}
@if(($carryOver['has_prior'] ?? false))
    <div class="alert {{ $carryOver['outstanding'] > 0 ? 'alert-danger' : 'alert-success' }} d-flex flex-wrap align-items-center gap-3 mb-3">
        <i class="bi {{ $carryOver['outstanding'] > 0 ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill' }} fs-4"></i>
        <div class="flex-grow-1">
            @if($carryOver['outstanding'] > 0)
                <strong>تنبيه: هذه الطاولة عليها طلبات سابقة غير مدفوعة.</strong>
                <div class="small mt-1">
                    {{ $carryOver['orders_count'] }} طلب — المتبقّي
                    <strong>{{ \App\Helpers\Money::format($carryOver['outstanding']) }}</strong>.
                    تأكّد أنّ الزبون السابق دفع وحرّر الطاولة قبل إضافة طلب جديد، وإلا سيُضاف على نفس الحساب.
                </div>
            @else
                <strong>الطلبات السابقة على هذه الطاولة مدفوعة بالكامل.</strong>
                <div class="small mt-1">تقدر تكمّل بأمان أو تحرّر الطاولة لبدء جلسة جديدة.</div>
            @endif
        </div>
        <div class="d-flex gap-2">
            {{-- Settle / free the table from the cashier — that's where payment
                 is collected and the session is closed so the table frees up. --}}
            <a href="{{ route('admin.cashier.index') }}" class="btn btn-sm btn-outline-dark">
                <i class="bi bi-receipt"></i> الذهاب للكاشير
            </a>
        </div>
    </div>
@endif

{{-- ─── Context panels: staff-mode + customer link ─────────────────────
     These stay native <form>s POSTing to the existing controller routes
     (waiter-orders.staff_mode / waiter-orders.customer.link). They redirect
     back here and re-mount the POS component below. The component's cart is
     mirrored to the session on every change, so a redirect from either of
     these panels NEVER loses the in-progress cart. --}}
<div class="row g-3 mb-3">
    {{-- Staff meal panel — shown first so a waiter taking a quick
         "for the manager" order doesn't accidentally attach the manager as
         a paying customer. Either mode is active at a time, never both. --}}
    @if($eligibleStaff->isNotEmpty())
        @php
            $staffActive = ! is_null($staffMember);
            $staffSummary = $staffActive
                ? app(\App\Services\StaffMealService::class)->monthSummary($staffMember)
                : null;
        @endphp
        <div class="{{ $session->customer_id || ! $staffActive ? 'col-lg-6' : 'col-12' }}">
            <details class="card wo-collapse h-100 {{ $staffActive ? 'border-warning' : '' }}" {{ $staffActive ? 'open' : '' }}>
                <summary class="card-header d-flex justify-content-between align-items-center" style="cursor: pointer; list-style:none;">
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
                    <i class="bi bi-chevron-down wo-chevron"></i>
                </summary>
                <div class="card-body">
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
            </details>
        </div>
    @endif

    {{-- Customer attach panel — collapsible to keep the menu's first
         impression compact. The waiter only opens it to link a phone. --}}
    <div class="{{ $eligibleStaff->isNotEmpty() ? 'col-lg-6' : 'col-12' }}">
        <details class="card wo-collapse h-100" {{ $session->customer_id ? 'open' : '' }}>
            <summary class="card-header d-flex justify-content-between align-items-center" style="cursor: pointer; list-style:none;">
                <div>
                    <i class="bi bi-person-circle"></i>
                    <strong>الزبون</strong>
                    @if($session->customer_id)
                        <span class="badge bg-success ms-2">
                            مرتبط: {{ $session->customer_name ?? '#'.$session->customer_id }}
                        </span>
                    @else
                        <span class="text-muted small">— غير محدد (اضغط للإضافة)</span>
                    @endif
                </div>
                <i class="bi bi-chevron-down wo-chevron"></i>
            </summary>
            <div class="card-body">
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
        </details>
    </div>
</div>

{{-- ─── LIVE POS ───────────────────────────────────────────────────────
     Reactive tile grid + live cart + submit. No page reload on add/remove/
     qty. The component resolves/opens the same active TableSession the
     controller did and drives the whole order-taking UX. --}}
<livewire:admin.waiter-pos :table-id="$table->id" :key="'waiter-pos-'.$table->id" />

{{-- ─── Bank-transfer claim ────────────────────────────────────────────
     Visible once the session has at least one submitted (approved+) order.
     The customer paid from their banking app and showed the waiter; the
     cashier confirms later in the bank's own dashboard. --}}
@php
    $payableOrders = $session->orders()
        ->whereIn('status', ['approved', 'preparing', 'ready', 'delivered', 'completed'])
        ->get(['id', 'total']);
    $sessionPayableCount = $payableOrders->count();
    $sessionTotalGuess = (float) $payableOrders->sum('total');
@endphp
@if($sessionPayableCount > 0)
    <div class="card mt-3 border-info">
        <div class="card-header bg-info-transparent d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-bank text-info"></i>
                <strong>دفع بتحويل بنكي</strong>
            </div>
            <button type="button" class="btn btn-sm btn-info"
                    data-bs-toggle="modal" data-bs-target="#transferModal">
                <i class="bi bi-cash-coin"></i> تسجيل تحويل
            </button>
        </div>
        <div class="card-body">
            <small class="text-muted d-block">
                بعد أن يستلم الزبون طلبه ويحوّل المبلغ على حساب المطعم من
                تطبيق البنك، أدخل التفاصيل وسيظهر للكاشير للتأكد من البنك.
            </small>
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

<style>
    /* Native <details> collapse — no JS/Alpine needed. Rotates the
       chevron and removes the default disclosure triangle. */
    .wo-collapse > summary { user-select: none; }
    .wo-collapse > summary::-webkit-details-marker { display: none; }
    .wo-collapse .wo-chevron { transition: transform .15s ease; }
    .wo-collapse[open] .wo-chevron { transform: rotate(180deg); }
</style>
@endsection
