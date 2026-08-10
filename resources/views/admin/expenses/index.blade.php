@extends('layouts.admin')
@section('title', 'المصروفات التشغيلية')

@php
    $cur = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', 'د.أ'));
    $money = fn ($v) => number_format((float) $v, 2) . ' ' . $cur;
    $showBranchCol = (bool) session('view_all_branches');
@endphp

@section('content')
<x-admin.breadcrumb title="المصروفات التشغيلية" icon="bi-cash-coin"
    subtitle="سجّل مصروفات الفرع وتابعها من مكان واحد" />

<x-admin.stat-rail :stats="[
    ['label' => 'بانتظار الاعتماد',  'value' => $stats['pending'],                'icon' => 'bi-hourglass-split',  'color' => 'warning'],
    ['label' => 'مصروفات اليوم',    'value' => $money($stats['today_total']),    'icon' => 'bi-calendar-day',     'color' => 'primary'],
    ['label' => 'إجمالي الشهر',     'value' => $money($stats['month_total']),    'icon' => 'bi-bar-chart-fill',   'color' => 'accent'],
]" />

<x-admin.data-panel title="سجل المصروفات التشغيلية" :count="$expenses->total()" icon="bi-cash-coin">
    <x-slot:actions>
        @can('create', \App\Models\Expense::class)
            <a href="{{ route('admin.expenses.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> مصروف جديد
            </a>
        @endcan
    </x-slot:actions>

    <x-slot:filters>
        <details @if(request()->hasAny(['search','from','to','category_id','status','payment_method'])) open @endif>
            <summary class="fw-semibold text-primary" style="cursor:pointer">
                <i class="bi bi-search me-1"></i> بحث وتصفية
            </summary>
        <form class="row g-2 align-items-end mt-2">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">بحث</label>
                <input type="text" name="search" value="{{ request('search') }}"
                       class="form-control" placeholder="رقم / وصف / مورد…">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">من تاريخ</label>
                <input type="date" name="from" value="{{ request('from') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">إلى تاريخ</label>
                <input type="date" name="to" value="{{ request('to') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">التصنيف</label>
                <select name="category_id" class="form-select" data-relax-choice data-choice-search-placeholder="ابحث عن تصنيف...">
                    <option value="">الكل</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected((int) request('category_id') === (int) $cat->id)>{{ $cat->label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">الحالة</label>
                <select name="status" class="form-select">
                    <option value="">الكل</option>
                    <option value="pending_approval" @selected(request('status')==='pending_approval')>في الانتظار</option>
                    <option value="approved"         @selected(request('status')==='approved')>معتمد</option>
                    <option value="rejected"         @selected(request('status')==='rejected')>مرفوض</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">طريقة الدفع</label>
                <select name="payment_method" class="form-select">
                    <option value="">الكل</option>
                    @foreach($paymentMethods as $key => $label)
                        <option value="{{ $key }}" @selected(request('payment_method') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 text-center mt-2">
                <button class="btn btn-primary px-5" title="استعلام">
                    <i class="bi bi-funnel"></i> استعلام
                </button>
            </div>
            @if(request()->hasAny(['search','from','to','category_id','status','payment_method']))
                <div class="col-12">
                    <a href="{{ route('admin.expenses.index') }}" class="small text-muted">
                        <i class="bi bi-x-circle"></i> مسح كل الفلاتر
                    </a>
                </div>
            @endif
        </form>
        <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
            <a href="{{ route('admin.expenses.index', array_merge(request()->query(), ['export' => 'xlsx'])) }}"
               class="btn btn-sm btn-outline-success">
                <i class="bi bi-file-earmark-excel-fill"></i> تصدير Excel
            </a>
            @can('viewAny', \App\Models\Lookup::class)
                <a href="{{ route('admin.lookups.index', ['group' => 'expense_categories']) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-tags"></i> إدارة التصنيفات
                </a>
            @endcan
        </div>
        </details>
    </x-slot:filters>

    @if(request()->hasAny(['search','from','to','category_id','status','payment_method']))
        <div class="alert alert-light border py-2 mb-3">
            النتائج: <strong>{{ number_format($filteredStats['count']) }}</strong>
            <span class="mx-2 text-muted">|</span>
            الإجمالي: <strong class="text-primary">{{ $money($filteredStats['total']) }}</strong>
        </div>
    @endif

    @if($categories->isEmpty())
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-18"></i>
            <div>
                لا توجد تصنيفات مصروفات مفعّلة. أضف تصنيفاً من
                <a href="{{ route('admin.lookups.index', ['group' => 'expense_categories']) }}" class="alert-link">إدارة الثوابت</a>
                حتى يستطيع الفريق تسجيل المصروفات بشكل مرتب.
            </div>
        </div>
    @endif

    <div class="table-responsive">
        <table class="table align-middle exp-table">
            <thead class="bg-light">
                <tr>
                    <th>#</th>
                    @if($showBranchCol)<th>الفرع</th>@endif
                    <th>الوصف / المورد</th>
                    <th>المبلغ</th>
                    <th>التاريخ</th>
                    <th>الحالة</th>
                    <th class="text-end">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($expenses as $exp)
                    <tr class="exp-row exp-row--{{ $exp->status }}">
                        <td>
                            <code class="exp-num">{{ $exp->expense_number }}</code>
                            @if($exp->attachment_path)
                                <a href="{{ asset('storage/'.$exp->attachment_path) }}" target="_blank"
                                   class="badge bg-info-transparent ms-1" title="الإيصال">
                                    <i class="bi bi-paperclip"></i>
                                </a>
                            @endif
                        </td>
                        @if($showBranchCol)
                            <td><x-admin.branch-tag :branch="$exp->branch" /></td>
                        @endif
                        <td>
                            <div class="fw-bold">{{ $exp->description }}</div>
                            @if($exp->vendor_name || $exp->supplier)
                                <small class="text-muted d-block">
                                    <i class="bi bi-shop-window"></i>
                                    {{ $exp->supplier->name ?? $exp->vendor_name }}
                                </small>
                            @endif
                            @if($exp->notes)
                                <small class="text-muted d-block fst-italic">{{ \Illuminate\Support\Str::limit($exp->notes, 60) }}</small>
                            @endif
                            @php $cat = $exp->category; @endphp
                            <div class="mt-1">
                                <span class="badge" style="{{ $cat?->badgeStyle() ?: 'background:#f3f4f6;color:#6b7280;' }}">
                                    {{ $cat?->label ?? '—' }}
                                </span>
                                <small class="text-muted ms-2">{{ $exp->paymentMethodLabel() }}</small>
                            </div>
                        </td>
                        <td class="exp-amount">{{ $money($exp->amount) }}</td>
                        <td>
                            <span class="exp-date">{{ $exp->expense_date->format('Y/m/d') }}</span>
                        </td>
                        <td>
                            <span class="badge bg-{{ $exp->statusColor() }}">{{ $exp->statusLabel() }}</span>
                            @if($exp->isRejected() && $exp->rejection_reason)
                                <small class="d-block text-danger mt-1" title="{{ $exp->rejection_reason }}">
                                    <i class="bi bi-info-circle"></i> {{ \Illuminate\Support\Str::limit($exp->rejection_reason, 30) }}
                                </small>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="exp-actions">
                                {{-- Action buttons shown ONLY while pending. The policy
                                     also enforces this, but `BasePolicy::before` lets
                                     owner-level bypass per-method checks — so we gate
                                     on the model state in the view as well. Approved
                                     and rejected rows show no mutation buttons. --}}
                                @if($exp->isPending())
                                    @can('approve', $exp)
                                        <form action="{{ route('admin.expenses.approve', $exp) }}" method="POST" class="d-inline"
                                              onsubmit="return confirm('اعتماد هذا المصروف؟');">
                                            @csrf
                                            <button class="btn btn-sm btn-success" title="اعتماد">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                    @endcan
                                    @can('reject', $exp)
                                        <button class="btn btn-sm btn-outline-danger" title="رفض"
                                                data-bs-toggle="modal" data-bs-target="#expRejectModal"
                                                data-exp-id="{{ $exp->id }}"
                                                data-exp-num="{{ $exp->expense_number }}">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    @endcan
                                    @can('update', $exp)
                                        <a href="{{ route('admin.expenses.edit', $exp) }}"
                                           class="btn btn-sm btn-light" title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    @endcan
                                @endif
                                @can('delete', $exp)
                                    <form action="{{ route('admin.expenses.destroy', $exp) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('حذف المصروف {{ $exp->expense_number }}؟');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="حذف">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showBranchCol ? 7 : 6 }}">
                        <x-admin.empty-state icon="bi-cash-coin"
                            title="لا توجد مصروفات بعد"
                            message="ابدأ بتسجيل أول مصروف للفرع." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($expenses->hasPages())
        <x-slot:footer>{{ $expenses->links() }}</x-slot:footer>
    @endif
</x-admin.data-panel>

{{-- ─── Reject modal ──────────────────────────────────────────────── --}}
<div class="modal fade" id="expRejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="expRejectForm" class="modal-content">
            @csrf
            <div class="modal-header bg-danger-transparent">
                <h6 class="modal-title">
                    <i class="bi bi-x-octagon"></i>
                    رفض المصروف <code id="expRejectNum"></code>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                <textarea name="rejection_reason" class="form-control" rows="3"
                          required maxlength="255"
                          placeholder="اشرح سبب رفض هذا المصروف…"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger"><i class="bi bi-x-lg"></i> ارفض</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    /* Table row tints by status */
    .exp-row--pending_approval { background: rgba(251, 191, 36, .04); }
    .exp-row--rejected         { background: rgba(239, 68, 68, .03); }
    .exp-row--approved td      { /* default */ }

    .exp-num {
        background: #f9fafb;
        padding: 2px 7px;
        border-radius: 5px;
        font-size: .76rem;
        color: #1f2937;
        font-weight: 700;
    }
    .exp-amount {
        font-family: ui-monospace, monospace;
        font-weight: 800;
        color: #1f2937;
        white-space: nowrap;
    }
    .exp-date {
        font-family: ui-monospace, monospace;
        font-weight: 700;
    }
    .exp-actions {
        display: inline-flex; gap: 4px;
    }
    .exp-actions .btn { padding: 4px 8px; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const baseUrl = "{{ url('admin/expenses') }}";

    // Reject modal — populate action URL + reset textarea.
    const rj = document.getElementById('expRejectModal');
    if (rj) {
        rj.addEventListener('show.bs.modal', (e) => {
            const t = e.relatedTarget; if (!t) return;
            const f = document.getElementById('expRejectForm');
            f.action = `${baseUrl}/${t.dataset.expId}/reject`;
            f.querySelector('[name="rejection_reason"]').value = '';
            document.getElementById('expRejectNum').textContent = t.dataset.expNum;
        });
    }
})();
</script>
@endpush
@endsection
