@extends('layouts.admin')
@section('title', 'العملاء')

@php
    $scopeLabel = $activeBranch
        ? 'عملاء فرع «' . $activeBranch->name . '» (الفرع المفضّل أو لديهم حجز)'
        : 'كل العملاء (موحّد لكل الفروع)';
@endphp

@section('content')
<x-admin.breadcrumb title="العملاء" icon="bi-person-hearts"
    :subtitle="$scopeLabel" />

<x-admin.stat-rail :stats="[
    ['label' => 'إجمالي العملاء',     'value' => $stats['total'],      'icon' => 'bi-people-fill',     'color' => 'primary'],
    ['label' => 'جدد اليوم',          'value' => $stats['new_today'],  'icon' => 'bi-person-plus',     'color' => 'success'],
    ['label' => 'جدد هذا الشهر',      'value' => $stats['new_month'],  'icon' => 'bi-calendar-month',  'color' => 'accent'],
    ['label' => 'نشطون (٣٠ يوم)',     'value' => $stats['active_30d'], 'icon' => 'bi-activity',        'color' => 'muted'],
]" />

<x-admin.data-panel title="قائمة العملاء" :count="$customers->total()" icon="bi-person-hearts">
    <x-slot:filters>
        <form class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">بحث</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="اسم / هاتف / إيميل…">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">الحالة</label>
                <select name="status" class="form-select">
                    <option value="">الكل</option>
                    <option value="active"  @selected(request('status')==='active')>نشط</option>
                    <option value="blocked" @selected(request('status')==='blocked')>محظور</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1"><i class="bi bi-funnel"></i> تطبيق</button>
                @if(request()->hasAny(['search','status','new_today']))
                    <a href="{{ route('admin.customers.index') }}" class="btn btn-light" title="مسح">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
            <div class="col-12">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i>
                    نطاق العرض يتبع الفرع النشط في الأعلى — استخدم زر تبديل الفرع للتنقل بين الفروع.
                </small>
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle cust-table">
            <thead class="bg-light">
                <tr>
                    <th>العميل</th>
                    <th>التواصل</th>
                    <th>الفرع المفضّل</th>
                    <th class="text-center">الحجوزات</th>
                    <th>التسجيل</th>
                    <th>آخر دخول</th>
                    <th>الحالة</th>
                    <th class="text-end">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $c)
                    <tr class="cust-row {{ $c->isBlocked() ? 'cust-row--blocked' : '' }}">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="cust-avatar" style="--hue: {{ ($c->id * 47) % 360 }};">
                                    {{ mb_substr(trim($c->name) ?: '؟', 0, 1, 'UTF-8') }}
                                </span>
                                <div>
                                    <a href="{{ route('admin.customers.show', $c) }}" class="fw-bold text-dark text-decoration-none">{{ $c->name }}</a>
                                    @if($c->gender)
                                        <small class="text-muted d-block">
                                            @switch($c->gender)
                                                @case('male')   <i class="bi bi-gender-male"></i> ذكر @break
                                                @case('female') <i class="bi bi-gender-female"></i> أنثى @break
                                                @default —
                                            @endswitch
                                        </small>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="cust-contact">
                                <i class="bi bi-telephone-fill"></i>
                                <a href="tel:{{ $c->phone }}" dir="ltr">{{ $c->phone }}</a>
                            </div>
                            @if($c->email)
                                <div class="cust-contact text-muted">
                                    <i class="bi bi-envelope"></i>
                                    <span dir="ltr">{{ $c->email }}</span>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($c->defaultBranch)
                                <x-admin.branch-tag :branch="$c->defaultBranch" />
                            @else
                                <span class="text-muted small">— لم يحدد —</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info-transparent">{{ $c->reservations_count }}</span>
                        </td>
                        <td>
                            <small>{{ $c->created_at->format('Y/m/d') }}</small>
                            <small class="d-block text-muted">{{ $c->created_at->diffForHumans() }}</small>
                        </td>
                        <td>
                            @if($c->last_login_at)
                                <small>{{ $c->last_login_at->diffForHumans() }}</small>
                            @else
                                <small class="text-muted">— لم يدخل —</small>
                            @endif
                        </td>
                        <td>
                            @if($c->isBlocked())
                                <span class="badge bg-danger" title="{{ $c->blocked_reason }}">
                                    <i class="bi bi-slash-circle"></i> محظور
                                </span>
                            @else
                                <span class="badge bg-success">نشط</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.customers.show', $c) }}" class="btn btn-sm btn-light" title="التفاصيل">
                                <i class="bi bi-eye"></i>
                            </a>
                            @can('block', $c)
                                @if($c->isBlocked())
                                    <form action="{{ route('admin.customers.unblock', $c) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('إلغاء حظر {{ $c->name }}؟');">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-success" title="إلغاء الحظر">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                @else
                                    <button class="btn btn-sm btn-outline-warning" title="حظر"
                                            data-bs-toggle="modal" data-bs-target="#custBlockModal"
                                            data-cust-id="{{ $c->id }}" data-cust-name="{{ $c->name }}">
                                        <i class="bi bi-slash-circle"></i>
                                    </button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">
                        <x-admin.empty-state icon="bi-person-hearts"
                            title="لا يوجد عملاء"
                            message="لم يسجّل أي عميل عبر بوابة العملاء بعد." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($customers->hasPages())
        <x-slot:footer>{{ $customers->links() }}</x-slot:footer>
    @endif
</x-admin.data-panel>

{{-- ─── Block modal ───────────────────────────────────────────────── --}}
<div class="modal fade" id="custBlockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="custBlockForm" class="modal-content">
            @csrf
            <div class="modal-header bg-warning-transparent">
                <h6 class="modal-title">
                    <i class="bi bi-slash-circle"></i>
                    حظر العميل <strong id="custBlockName"></strong>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">سبب الحظر <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="255"
                          placeholder="اشرح سبب حظر هذا العميل…"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-warning"><i class="bi bi-slash-circle"></i> احظر</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    .cust-avatar {
        width: 36px; height: 36px;
        border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg,
            hsl(var(--hue, 150) 50% 52%) 0%,
            hsl(var(--hue, 150) 55% 38%) 100%);
        color: #fff;
        font-weight: 800;
        font-size: .9rem;
        flex-shrink: 0;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25);
    }
    .cust-contact {
        font-size: .82rem;
        display: flex; align-items: center; gap: 6px;
    }
    .cust-contact a { color: #1f2937; text-decoration: none; }
    .cust-contact a:hover { color: rgb(var(--primary-rgb)); }
    .cust-contact i { color: #9ca3af; font-size: .78rem; }
    .cust-row--blocked { background: rgba(239, 68, 68, .03); }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const baseUrl = "{{ url('admin/customers') }}";
    const m = document.getElementById('custBlockModal');
    if (!m) return;
    m.addEventListener('show.bs.modal', (e) => {
        const t = e.relatedTarget; if (!t) return;
        document.getElementById('custBlockForm').action = `${baseUrl}/${t.dataset.custId}/block`;
        document.getElementById('custBlockName').textContent = t.dataset.custName;
    });
})();
</script>
@endpush
@endsection
