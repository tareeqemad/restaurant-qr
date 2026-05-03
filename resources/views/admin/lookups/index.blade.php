@extends('layouts.admin')
@section('title', 'إدارة الثوابت')

@php
    $meta = $groups[$activeGroup];
@endphp

@section('content')
<x-admin.breadcrumb title="إدارة الثوابت" icon="bi-list-ul"
    subtitle="تعديل القوائم المستخدمة في أنحاء النظام (تصنيفات المصروفات، إلخ.)" />

{{-- ─── Group tabs ───────────────────────────────────────────────── --}}
<div class="lk-tabs mb-3">
    @foreach($groups as $key => $g)
        <a href="{{ route('admin.lookups.index', ['group' => $key]) }}"
           id="{{ $key }}"
           class="lk-tab {{ $key === $activeGroup ? 'lk-tab--active' : '' }}">
            <i class="bi {{ $g['icon'] }}"></i>
            <span class="lk-tab__label">{{ $g['label'] }}</span>
            <span class="lk-tab__count">{{ $usage[$key] ?? 0 }}</span>
        </a>
    @endforeach
</div>

<x-admin.data-panel :title="$meta['label']" :count="$rows->count()" :icon="$meta['icon']">
    <x-slot:actions>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lkAddModal">
            <i class="bi bi-plus-lg"></i> إضافة قيمة
        </button>
    </x-slot:actions>

    <p class="text-muted small mb-3">
        <i class="bi bi-info-circle"></i> {{ $meta['subtitle'] }}
    </p>

    <div class="table-responsive">
        <table class="table align-middle lk-table">
            <thead class="bg-light">
                <tr>
                    <th style="width:60px">#</th>
                    <th>التسمية</th>
                    <th>الكود</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th>النوع</th>
                    <th class="text-end">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr class="{{ $row->trashed() ? 'lk-row--trashed' : '' }}">
                        <td>
                            <span class="lk-icon-cell" style="{{ $row->color ? 'background:'.$row->color.'20;color:'.$row->color : '' }}">
                                <i class="bi {{ $row->icon ?: 'bi-circle' }}"></i>
                            </span>
                        </td>
                        <td>
                            <span class="badge" style="{{ $row->badgeStyle() ?: 'background:#f3f4f6;color:#374151;' }}">
                                @if($row->icon)<i class="bi {{ $row->icon }}"></i>@endif
                                {{ $row->label }}
                            </span>
                        </td>
                        <td><code class="lk-code">{{ $row->code ?? '—' }}</code></td>
                        <td><span class="lk-order">{{ $row->display_order }}</span></td>
                        <td>
                            @if($row->trashed())
                                <span class="badge bg-secondary">محذوف</span>
                            @elseif($row->is_active)
                                <span class="badge bg-success">مفعّل</span>
                            @else
                                <span class="badge bg-warning">معطّل</span>
                            @endif
                        </td>
                        <td>
                            @if($row->is_system)
                                <span class="badge bg-info-transparent" title="مزروعة من النظام — لا يمكن حذفها">
                                    <i class="bi bi-shield-lock"></i> نظامية
                                </span>
                            @else
                                <span class="badge bg-secondary-transparent">مخصّصة</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if($row->trashed())
                                <form action="{{ route('admin.lookups.restore', $row->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success" title="استرجاع">
                                        <i class="bi bi-arrow-counterclockwise"></i> استرجاع
                                    </button>
                                </form>
                            @else
                                <button class="btn btn-sm btn-light"
                                        data-bs-toggle="modal" data-bs-target="#lkEditModal"
                                        data-id="{{ $row->id }}"
                                        data-label="{{ $row->label }}"
                                        data-code="{{ $row->code }}"
                                        data-color="{{ $row->color }}"
                                        data-icon="{{ $row->icon }}"
                                        data-order="{{ $row->display_order }}"
                                        data-active="{{ $row->is_active ? 1 : 0 }}"
                                        data-system="{{ $row->is_system ? 1 : 0 }}"
                                        title="تعديل">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if(! $row->is_system)
                                    <form action="{{ route('admin.lookups.destroy', $row) }}" method="POST"
                                          class="d-inline" onsubmit="return confirm('إخفاء هذه القيمة؟ (السجلات الحالية تبقى مرتبطة بها)');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="إخفاء">
                                            <i class="bi bi-eye-slash"></i>
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">
                        <x-admin.empty-state icon="bi-list-ul"
                            title="لا توجد قيم بعد"
                            message="ابدأ بإضافة أول قيمة لهذا التصنيف." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin.data-panel>

{{-- ─── Add modal ─────────────────────────────────────────────────── --}}
<div class="modal fade" id="lkAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('admin.lookups.store') }}" class="modal-content">
            @csrf
            <input type="hidden" name="group" value="{{ $activeGroup }}">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-plus-circle"></i>
                    إضافة قيمة إلى «{{ $meta['label'] }}»
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @include('admin.lookups._fields', ['isEdit' => false])
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>

{{-- ─── Edit modal ────────────────────────────────────────────────── --}}
<div class="modal fade" id="lkEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="lkEditForm" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-pencil-square"></i> تعديل القيمة
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="lkSystemHint" class="alert alert-info py-2 small d-none">
                    <i class="bi bi-shield-lock"></i>
                    قيمة نظامية — يمكنك تعديل التسمية واللون والأيقونة فقط.
                </div>
                @include('admin.lookups._fields', ['isEdit' => true])
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ التعديلات</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style>
    .lk-tabs {
        display: flex; flex-wrap: wrap; gap: 6px;
        background: #fff;
        padding: 8px;
        border-radius: 10px;
        border: 1px solid #e5e7eb;
    }
    .lk-tab {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 14px;
        background: transparent;
        color: #6b7280;
        border-radius: 8px;
        font-weight: 600;
        font-size: .85rem;
        text-decoration: none;
        transition: background .15s;
    }
    .lk-tab:hover { background: #f9fafb; color: #1f2937; }
    .lk-tab--active {
        background: var(--brand-primary, #047857);
        color: #fff !important;
    }
    .lk-tab__count {
        background: rgba(255,255,255,.2);
        padding: 1px 8px;
        border-radius: 99px;
        font-size: .7rem;
        font-weight: 800;
    }
    .lk-tab:not(.lk-tab--active) .lk-tab__count {
        background: #f3f4f6;
        color: #6b7280;
    }

    .lk-icon-cell {
        display: inline-flex; align-items: center; justify-content: center;
        width: 36px; height: 36px;
        border-radius: 8px;
        background: #f3f4f6;
        color: #6b7280;
        font-size: 1.1rem;
    }
    .lk-code {
        background: #f9fafb;
        padding: 2px 7px;
        border-radius: 5px;
        font-size: .76rem;
        color: #1f2937;
    }
    .lk-order {
        font-family: ui-monospace, monospace;
        font-weight: 700;
        color: #6b7280;
    }
    .lk-row--trashed { opacity: .55; }
    .lk-row--trashed td { background: #fafafa; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const baseUrl = "{{ url('admin/lookups') }}";
    const editModal = document.getElementById('lkEditModal');
    if (!editModal) return;

    editModal.addEventListener('show.bs.modal', (e) => {
        const t = e.relatedTarget; if (!t) return;
        const f = document.getElementById('lkEditForm');
        f.action = `${baseUrl}/${t.dataset.id}`;
        f.querySelector('[name="label"]').value         = t.dataset.label || '';
        f.querySelector('[name="code"]').value          = t.dataset.code  || '';
        f.querySelector('[name="color"]').value         = t.dataset.color || '#94a3b8';
        f.querySelector('[name="icon"]').value          = t.dataset.icon  || '';
        f.querySelector('[name="display_order"]').value = t.dataset.order || 0;
        f.querySelector('[name="is_active"]').checked   = t.dataset.active === '1';

        // System rows: lock the code field, show explanatory hint.
        const isSystem = t.dataset.system === '1';
        f.querySelector('[name="code"]').readOnly = isSystem;
        document.getElementById('lkSystemHint').classList.toggle('d-none', !isSystem);
    });
})();
</script>
@endpush
@endsection
