@extends('layouts.admin')
@section('title','الورديات')

@php
    $branchId   = \App\Support\BranchContext::current();
    $branchName = $branchId
        ? (\App\Models\Branch::find($branchId)?->name ?? '—')
        : 'كل الفروع';
    $currency   = config('restaurant.currency_symbol', '₪');
@endphp

@section('content')
<x-admin.breadcrumb
    title="الورديات (الشفت)"
    icon="bi-clock-history"
    subtitle="افتح شفت الكاشير في بداية الدوام، أغلقه عند الانتهاء، والنظام يحسب فرق الكاش تلقائياً." />

{{-- ═══════════════════ Branch context chip ═══════════════════ --}}
<div class="sh-context mb-3">
    <div class="sh-context__main">
        <i class="bi {{ $branchId ? 'bi-shop' : 'bi-building-fill' }}"></i>
        <span>الورديات معروضة لـ:</span>
        <strong>{{ $branchName }}</strong>
        @if($branchId)
            <small class="ms-2">— شفتك في هذا الفرع وحده. تبديل الفرع يعرض شفت ذاك الفرع.</small>
        @else
            <small class="ms-2">— شفتات كل الفروع. اختر فرعاً محدداً لتفتح/تغلق فيه شفتاً.</small>
        @endif
    </div>
</div>

{{-- ═══════════════════ Help banner — explains how shifts work ═══════════════════ --}}
<details class="sh-help mb-3" {{ $activeShift ? '' : 'open' }}>
    <summary class="sh-help__head">
        <span class="sh-help__title">
            <i class="bi bi-info-circle-fill"></i>
            شو الـ«شفت» وكيف أستخدم هذه الشاشة؟
        </span>
        <i class="bi bi-chevron-down sh-help__caret"></i>
    </summary>
    <div class="sh-help__body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="sh-help__card">
                    <div class="sh-help__num">١</div>
                    <h6>شو هو الشفت؟</h6>
                    <p>
                        فترة عمل الكاشير من بداية دوامه لنهايته. كل دفعة كاش (أو كارد)
                        تتم خلال هذه الفترة <b>تُربط تلقائياً</b> بهذا الشفت — عشان تقدر
                        تراجع الدرج في النهاية وتطلع نص حساب يومي عن كل كاشير.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="sh-help__card">
                    <div class="sh-help__num">٢</div>
                    <h6>كيف أبدأ؟</h6>
                    <p>
                        في بداية الدوام، عُدّ النقد الموجود في الدرج واكتبه كـ
                        «كاش الافتتاح». اضغط <b>«فتح الشفت»</b> → النظام يبدأ بربط
                        كل دفعاتك بهذا الشفت. <b>شفت واحد مفتوح لكل كاشير في كل فرع.</b>
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="sh-help__card">
                    <div class="sh-help__num">٣</div>
                    <h6>كيف أغلق وأشيك؟</h6>
                    <p>
                        في نهاية الدوام، عُدّ النقد الفعلي في الدرج واكتبه كـ
                        «كاش الإغلاق». النظام يحسب:
                        <br><b>المتوقع</b> = افتتاح + مبيعات الكاش
                        <br><b>الفرق</b> = الفعلي − المتوقع
                    </p>
                </div>
            </div>
            <div class="col-12">
                <div class="sh-help__warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>
                        <b>قراءة عمود «الفرق»:</b>
                        <span class="sh-tag sh-tag--ok">صفر</span> الدرج متطابق · ممتاز.
                        <span class="sh-tag sh-tag--neg">سالب</span> نقص في الدرج — قد يكون
                        خطأ في الباقي، فاتورة بدون تسجيل، أو سرقة.
                        <span class="sh-tag sh-tag--pos">موجب</span> فائض — نسي يصرف باقي
                        أو خطأ في عدّ الدرج. تابع الموظفين اللي يتكرر معاهم نفس النوع.
                    </div>
                </div>
            </div>
        </div>
    </div>
</details>

<div class="row g-3">
    {{-- ═══════════════════ Action card — open / close shift ═══════════════════ --}}
    <div class="col-lg-5">
        @if($activeShift)
            <div class="card border-success sh-active">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="sh-active__pulse"></span>
                        <h5 class="fw-bold text-success mb-0">شفت مفتوح الآن</h5>
                    </div>

                    <div class="sh-meta">
                        <div class="sh-meta__row">
                            <i class="bi bi-calendar-event"></i>
                            <span class="sh-meta__label">افتُتح:</span>
                            <strong>{{ $activeShift->opened_at->format('Y-m-d · H:i') }}</strong>
                            <small class="text-muted ms-2">({{ $activeShift->opened_at->diffForHumans() }})</small>
                        </div>
                        <div class="sh-meta__row">
                            <i class="bi bi-cash-coin"></i>
                            <span class="sh-meta__label">كاش الافتتاح:</span>
                            <strong>{{ \App\Helpers\Money::format($activeShift->cash_opening) }}</strong>
                        </div>
                        <div class="sh-meta__row">
                            <i class="bi bi-shop"></i>
                            <span class="sh-meta__label">الفرع:</span>
                            <strong>{{ $activeShift->branch?->name ?? '—' }}</strong>
                        </div>
                    </div>

                    <button class="btn btn-danger w-100 mt-3" data-bs-toggle="modal" data-bs-target="#close">
                        <i class="bi bi-box-arrow-right"></i> إغلاق الشفت الآن
                    </button>

                    <div class="modal fade" id="close" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form action="{{ route('admin.shifts.close', $activeShift) }}" method="POST">
                                    @csrf
                                    <div class="modal-header">
                                        <h5 class="modal-title">إغلاق الشفت</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="alert alert-info small mb-3">
                                            <i class="bi bi-info-circle-fill"></i>
                                            عُدّ النقد الفعلي في الدرج الآن — النظام سيقارنه بـ
                                            (افتتاح + مبيعات الكاش) ويعطيك الفرق.
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">
                                                الكاش المتوفر عند الإغلاق <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" name="cash_closing"
                                                       class="form-control text-end" required autofocus>
                                                <span class="input-group-text">{{ $currency }}</span>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label">ملاحظات <small class="text-muted">(اختياري)</small></label>
                                            <textarea name="notes" class="form-control" rows="2"
                                                      placeholder="مثلاً: كان فيه استرجاع نقدي لزبون لم يُسجل بعد..."></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                                        <button class="btn btn-danger">
                                            <i class="bi bi-check-circle-fill"></i> أغلق الشفت
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            @if($branchId)
                <div class="card border-primary sh-open-card">
                    <div class="card-body">
                        <h5 class="fw-bold text-primary">
                            <i class="bi bi-play-circle-fill"></i> فتح شفت جديد
                        </h5>
                        <p class="text-muted small mb-3">
                            ابدأ يومك بإدخال الكاش الموجود في الدرج. هذا الرقم
                            هو نقطة البداية لحساب الفرق عند الإغلاق.
                        </p>
                        <form action="{{ route('admin.shifts.store') }}" method="POST">
                            @csrf
                            <label class="form-label fw-bold">
                                الكاش الابتدائي في الدرج <span class="text-danger">*</span>
                            </label>
                            <div class="input-group mb-3">
                                <input type="number" step="0.01" min="0" name="cash_opening"
                                       class="form-control form-control-lg text-end"
                                       placeholder="0.00" required>
                                <span class="input-group-text">{{ $currency }}</span>
                            </div>
                            <button class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-play-fill"></i> ابدأ الشفت
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <div class="card border-warning">
                    <div class="card-body text-center py-4">
                        <i class="bi bi-building-fill-exclamation text-warning fs-32"></i>
                        <h6 class="mt-2">اختر فرعاً أولاً</h6>
                        <p class="text-muted small mb-0">
                            لا يمكن فتح شفت في وضع «كل الفروع».
                            استخدم مبدّل الفرع أعلى الصفحة لاختيار الفرع المناسب.
                        </p>
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- ═══════════════════ History table ═══════════════════ --}}
    <div class="col-lg-7">
        <div class="card sh-history">
            <div class="card-header d-flex align-items-center justify-content-between">
                <strong><i class="bi bi-list-ul"></i> سجل الورديات</strong>
                <small class="text-muted">{{ $shifts->total() }} شفت</small>
            </div>
            <div class="card-body p-0">
                @if($shifts->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-clock fs-32 d-block mb-2 opacity-50"></i>
                        لا توجد ورديات سابقة في هذا الفرع.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover sh-table mb-0">
                            <thead>
                                <tr>
                                    <th>الموظف</th>
                                    <th>الافتتاح</th>
                                    <th>الإغلاق</th>
                                    <th class="text-end">كاش</th>
                                    <th class="text-end">كارد</th>
                                    <th class="text-end" title="فرق الدرج: موجب=فائض، سالب=نقص، صفر=متطابق">
                                        الفرق <i class="bi bi-info-circle text-muted small"></i>
                                    </th>
                                    <th>الحالة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($shifts as $s)
                                    @php
                                        $variance = (float) $s->cash_variance;
                                        $varClass = abs($variance) < 0.01 ? 'sh-var--ok'
                                                  : ($variance < 0 ? 'sh-var--neg' : 'sh-var--pos');
                                    @endphp
                                    <tr>
                                        <td class="fw-semibold">{{ $s->user->name }}</td>
                                        <td>
                                            <div class="fs-13">{{ $s->opened_at->format('Y-m-d') }}</div>
                                            <small class="text-muted">{{ $s->opened_at->format('H:i') }}</small>
                                        </td>
                                        <td>
                                            @if($s->closed_at)
                                                <div class="fs-13">{{ $s->closed_at->format('Y-m-d') }}</div>
                                                <small class="text-muted">{{ $s->closed_at->format('H:i') }}</small>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $s->cash_sales, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $s->card_sales, 2) }}</td>
                                        <td class="text-end fw-bold {{ $varClass }}">
                                            @if($s->status === 'open')
                                                <span class="text-muted">—</span>
                                            @else
                                                {{ ($variance >= 0 ? '+' : '') . number_format($variance, 2) }}
                                            @endif
                                        </td>
                                        <td>
                                            @if($s->status === 'open')
                                                <span class="badge bg-success-transparent text-success">
                                                    <i class="bi bi-circle-fill" style="font-size:.5rem"></i> مفتوح
                                                </span>
                                            @else
                                                <span class="badge bg-secondary-transparent">مغلق</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            @if($shifts->hasPages())
                <div class="card-footer">{{ $shifts->links() }}</div>
            @endif
        </div>
    </div>
</div>

@push('styles')
<style>
    /* ─── Branch context ────────────────────────────────────────── */
    .sh-context {
        background: linear-gradient(90deg, #eff6ff 0%, #fff 100%);
        border: 1px solid #bfdbfe;
        border-radius: 12px;
        padding: .65rem 1rem;
    }
    .sh-context__main { display: inline-flex; align-items: center; gap: .5rem; font-size: .9rem; color: #1e3a8a; }
    .sh-context__main i { font-size: 1.1rem; color: #2563eb; }
    .sh-context__main strong { color: #0f172a; }

    /* ─── Help banner ──────────────────────────────────────────── */
    .sh-help { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; }
    .sh-help > summary { list-style: none; }
    .sh-help > summary::-webkit-details-marker { display: none; }
    .sh-help > summary::marker { display: none; content: ''; }
    .sh-help__head {
        display: flex; align-items: center; justify-content: space-between;
        background: linear-gradient(90deg, #f0fdf4 0%, #fff 100%);
        padding: .85rem 1.1rem; font-weight: 700; cursor: pointer;
        transition: background .15s; user-select: none;
    }
    .sh-help__head:hover { background: linear-gradient(90deg, #dcfce7 0%, #fff 100%); }
    .sh-help__title { display: inline-flex; align-items: center; gap: .55rem; color: #0f4731; }
    .sh-help__title i { color: #16a34a; font-size: 1.1rem; }
    .sh-help__caret { color: #64748b; transition: transform .2s ease; }
    .sh-help[open] .sh-help__caret { transform: rotate(180deg); }
    .sh-help__body { padding: 1rem 1.1rem; border-top: 1px solid #f1f5f9; }
    .sh-help__card {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 1rem; height: 100%; position: relative;
    }
    .sh-help__num {
        position: absolute; top: -10px; inset-inline-end: 12px;
        width: 28px; height: 28px;
        display: inline-flex; align-items: center; justify-content: center;
        background: #16a34a; color: #fff;
        border-radius: 50%; font-weight: 900; font-size: .85rem;
    }
    .sh-help__card h6 { font-weight: 800; color: #0f4731; margin-bottom: .5rem; }
    .sh-help__card p { font-size: .82rem; color: #475569; margin: 0; line-height: 1.7; }
    .sh-help__warn {
        display: flex; align-items: flex-start; gap: .75rem;
        background: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px;
        padding: .85rem 1rem; color: #78350f; font-size: .85rem; line-height: 1.7;
    }
    .sh-help__warn i { font-size: 1.3rem; color: #d97706; flex-shrink: 0; margin-top: 2px; }
    .sh-tag { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: .72rem; font-weight: 800; margin: 0 4px; }
    .sh-tag--ok  { background: #d1fae5; color: #065f46; }
    .sh-tag--neg { background: #fee2e2; color: #b91c1c; }
    .sh-tag--pos { background: #fef3c7; color: #b45309; }

    /* ─── Active shift card ──────────────────────────────────── */
    .sh-active__pulse {
        width: 12px; height: 12px; border-radius: 50%;
        background: #16a34a; position: relative;
    }
    .sh-active__pulse::after {
        content: ''; position: absolute; inset: -4px;
        border-radius: 50%; background: rgba(34, 197, 94, .35);
        animation: shPulse 1.6s ease-out infinite;
    }
    @keyframes shPulse {
        0%   { transform: scale(.6); opacity: 1; }
        100% { transform: scale(1.8); opacity: 0; }
    }
    .sh-meta { display: flex; flex-direction: column; gap: .5rem; }
    .sh-meta__row { display: flex; align-items: center; gap: .4rem; font-size: .9rem; }
    .sh-meta__row i { color: #16a34a; min-width: 18px; }
    .sh-meta__label { color: #64748b; }
    .sh-meta__row strong { color: #0f172a; font-weight: 700; }

    .sh-open-card .form-control-lg { font-size: 1.5rem; font-weight: 800; }

    /* ─── History table ──────────────────────────────────────── */
    .sh-table thead th { background: #f8fafc; color: #475569; font-weight: 700; font-size: .82rem; }
    .sh-table tbody td { font-size: .85rem; vertical-align: middle; }
    .sh-var--ok  { color: #065f46; }
    .sh-var--neg { color: #b91c1c; }
    .sh-var--pos { color: #b45309; }
</style>
@endpush
@endsection
