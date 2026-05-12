@extends('layouts.admin')
@section('title', 'المصروفات')

@php
    $cur = \App\Models\Setting::get('currency_symbol', config('restaurant.currency_symbol', 'د.أ'));
    $money = fn ($v) => number_format((float) $v, 2) . ' ' . $cur;
    $showBranchCol = (bool) session('view_all_branches');
@endphp

@section('content')
<x-admin.breadcrumb title="المصروفات" icon="bi-cash-coin"
    subtitle="سجل جميع المصروفات للفرع — اعتماد، رفض، وربط تلقائي بدرج الكاشير" />

<x-admin.stat-rail :stats="[
    ['label' => 'بانتظار الاعتماد',  'value' => $stats['pending'],                'icon' => 'bi-hourglass-split',  'color' => 'warning'],
    ['label' => 'مصروفات اليوم',    'value' => $money($stats['today_total']),    'icon' => 'bi-calendar-day',     'color' => 'primary'],
    ['label' => 'إجمالي الشهر',     'value' => $money($stats['month_total']),    'icon' => 'bi-bar-chart-fill',   'color' => 'accent'],
    ['label' => 'مرفوضة (٣٠ يوماً)', 'value' => $stats['rejected_30d'],          'icon' => 'bi-x-octagon',        'color' => 'muted'],
]" />

@if($byCategory->isNotEmpty())
<div class="exp-cat-rail mb-3">
    @foreach($byCategory as $row)
        @php
            $tint = $row->category_color
                ? "background:{$row->category_color}1a;color:{$row->category_color};border-color:{$row->category_color}40;"
                : '';
        @endphp
        <a href="{{ route('admin.expenses.index', array_merge(request()->query(), ['category_id' => $row->category_id])) }}"
           class="exp-cat-chip text-decoration-none" style="{{ $tint }}"
           title="عرض المصروفات في «{{ $row->category_label }}»">
            <span class="exp-cat-chip__name">
                @if($row->category_icon)<i class="bi {{ $row->category_icon }}"></i>@endif
                {{ $row->category_label ?? '— بدون تصنيف —' }}
            </span>
            <span class="exp-cat-chip__amount">{{ $money($row->total) }}</span>
            <span class="exp-cat-chip__count">{{ $row->cnt }}</span>
        </a>
    @endforeach
</div>
@endif

<x-admin.data-panel title="سجل المصروفات" :count="$expenses->total()" icon="bi-cash-coin">
    <x-slot:actions>
        @can('viewAny', \App\Models\Lookup::class)
            <a href="{{ route('admin.lookups.index', ['group' => 'expense_categories']) }}" class="btn btn-light">
                <i class="bi bi-tags"></i> تصنيفات المصروفات
            </a>
        @endcan
        @can('create', \App\Models\Expense::class)
            <a href="{{ route('admin.expenses.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> مصروف جديد
            </a>
        @endcan
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2 align-items-end">
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
    </x-slot:filters>

    <div class="exp-filter-summary mb-3">
        <div>
            <span class="exp-filter-summary__label">نتائج الاستعلام</span>
            <strong>{{ number_format($filteredStats['count']) }}</strong>
        </div>
        <div>
            <span class="exp-filter-summary__label">إجمالي النتائج</span>
            <strong class="text-primary">{{ $money($filteredStats['total']) }}</strong>
        </div>
        <div>
            <span class="exp-filter-summary__label">بانتظار الاعتماد</span>
            <strong class="text-warning">{{ number_format($filteredStats['pending']) }}</strong>
        </div>
        <div>
            <span class="exp-filter-summary__label">معتمدة</span>
            <strong class="text-success">{{ number_format($filteredStats['approved']) }}</strong>
        </div>
    </div>

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
                    <th>التصنيف</th>
                    <th>المبلغ</th>
                    <th>الدفع</th>
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
                        </td>
                        <td>
                            @php $cat = $exp->category; @endphp
                            <span class="badge" style="{{ $cat?->badgeStyle() ?: 'background:#f3f4f6;color:#6b7280;' }}">
                                @if($cat?->icon)<i class="bi {{ $cat->icon }}"></i>@endif
                                {{ $cat?->label ?? '— محذوف —' }}
                            </span>
                        </td>
                        <td class="exp-amount">{{ $money($exp->amount) }}</td>
                        <td>
                            <small class="d-block fw-bold">{{ $exp->paymentMethodLabel() }}</small>
                            @if($exp->payment_reference)
                                <small class="text-muted">{{ $exp->payment_reference }}</small>
                            @endif
                            @if($exp->cash_movement_id)
                                <small class="d-block">
                                    <span class="badge bg-success-transparent" title="تم خصم المبلغ من درج الكاشير">
                                        <i class="bi bi-link-45deg"></i> مرتبط بالكاشير
                                    </span>
                                </small>
                            @endif
                        </td>
                        <td>
                            <span class="exp-date">{{ $exp->expense_date->format('Y/m/d') }}</span>
                            <small class="text-muted d-block">بواسطة {{ $exp->creator->name ?? '—' }}</small>
                        </td>
                        <td>
                            <span class="badge bg-{{ $exp->statusColor() }}">{{ $exp->statusLabel() }}</span>
                            @if($exp->isApproved() && $exp->approver)
                                <small class="d-block text-muted mt-1">
                                    <i class="bi bi-check2-circle"></i> {{ $exp->approver->name }}
                                </small>
                            @elseif($exp->isRejected() && $exp->rejection_reason)
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
                                        <button class="btn btn-sm btn-success" title="اعتماد"
                                                data-bs-toggle="modal" data-bs-target="#expApproveModal"
                                                data-exp-id="{{ $exp->id }}"
                                                data-exp-num="{{ $exp->expense_number }}"
                                                data-exp-amount="{{ $money($exp->amount) }}"
                                                data-exp-cash="{{ $exp->isCash() ? '1' : '0' }}">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
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
                    <tr><td colspan="{{ $showBranchCol ? 9 : 8 }}">
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

{{-- ─── Approve modal ─────────────────────────────────────────────── --}}
<div class="modal fade" id="expApproveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="expApproveForm" class="modal-content">
            @csrf
            <div class="modal-header bg-success-transparent">
                <h6 class="modal-title">
                    <i class="bi bi-check2-circle"></i>
                    اعتماد المصروف <code id="expApproveNum"></code>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">سيتم اعتماد المصروف بمبلغ <strong id="expApproveAmount"></strong>.</p>
                <div id="expApproveCashHint" class="alert alert-info py-2 small d-none">
                    <i class="bi bi-info-circle"></i>
                    سيتم خصم المبلغ تلقائياً من درج الكاشير الحالي إن كانت هناك وردية مفتوحة في هذا الفرع.
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-success"><i class="bi bi-check-lg"></i> اعتمد</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>

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
    /* Category breakdown rail — under stat-rail, before the table */
    .exp-cat-rail {
        display: flex; flex-wrap: wrap; gap: 8px;
    }
    .exp-cat-chip {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 6px 12px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(0,0,0,.03);
        font-size: .82rem;
    }
    .exp-cat-chip__name { color: #374151; font-weight: 600; }
    .exp-cat-chip__amount { color: #047857; font-weight: 800; font-family: ui-monospace, monospace; }
    .exp-cat-chip__count {
        background: #f3f4f6; color: #6b7280; padding: 1px 7px;
        border-radius: 99px; font-size: .7rem; font-weight: 700;
    }
    .exp-filter-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 8px;
    }
    .exp-filter-summary > div {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        background: #fff;
        padding: 10px 12px;
        min-width: 0;
    }
    .exp-filter-summary__label {
        display: block;
        color: #6b7280;
        font-size: .76rem;
        margin-bottom: 3px;
    }
    .exp-filter-summary strong {
        font-size: .98rem;
        font-family: ui-monospace, monospace;
    }
    @media (max-width: 767.98px) {
        .exp-filter-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

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

    // Approve modal — populate the action URL + display the amount/cash hint.
    const ap = document.getElementById('expApproveModal');
    if (ap) {
        ap.addEventListener('show.bs.modal', (e) => {
            const t = e.relatedTarget; if (!t) return;
            document.getElementById('expApproveForm').action = `${baseUrl}/${t.dataset.expId}/approve`;
            document.getElementById('expApproveNum').textContent = t.dataset.expNum;
            document.getElementById('expApproveAmount').textContent = t.dataset.expAmount;
            document.getElementById('expApproveCashHint')
                .classList.toggle('d-none', t.dataset.expCash !== '1');
        });
    }

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
