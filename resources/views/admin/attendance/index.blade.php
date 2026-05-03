@extends('layouts.admin')
@section('title', 'الحضور والانصراف')

@php
    $minutesToHours = function (int $m): string {
        $h = intdiv($m, 60); $r = $m % 60;
        if ($h === 0) return "{$r} د";
        if ($r === 0) return "{$h} س";
        return "{$h} س {$r} د";
    };
    $showBranchCol = (bool) session('view_all_branches');
@endphp

@section('content')
<x-admin.breadcrumb title="الحضور والانصراف" icon="bi-clock-history"
    subtitle="سجل الموظفين — حاضر الآن، ساعات العمل اليومية، وتعديلات يدوية" />

<x-admin.stat-rail :stats="[
    ['label' => 'حاضرون الآن',     'value' => $stats['open_now'],                            'icon' => 'bi-person-check-fill', 'color' => 'success'],
    ['label' => 'سجلات اليوم',     'value' => $stats['today_count'],                          'icon' => 'bi-calendar-day',      'color' => 'primary'],
    ['label' => 'متوسط ساعات اليوم','value' => $minutesToHours($stats['today_avg']),          'icon' => 'bi-stopwatch',         'color' => 'accent'],
    ['label' => 'مجموع ساعات اليوم','value' => $minutesToHours($stats['today_total']),        'icon' => 'bi-bar-chart',         'color' => 'muted'],
]" />

<x-admin.data-panel title="السجلات" :count="$attendances->total()" icon="bi-clock-history">
    <x-slot:actions>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attAddModal">
            <i class="bi bi-plus-lg"></i> سجل يدوي
        </button>
    </x-slot:actions>

    <x-slot:filters>
        <form class="row g-2">
            <div class="col-md-3">
                <input type="date" name="date" value="{{ request('date') }}" class="form-control">
            </div>
            <div class="col-md-3">
                <select name="user_id" class="form-select">
                    <option value="">كل الموظفين</option>
                    @foreach($staff as $s)
                        <option value="{{ $s->id }}" @selected(request('user_id')==$s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">كل الحالات</option>
                    <option value="open"   @selected(request('status')==='open')>مفتوح (حاضر)</option>
                    <option value="closed" @selected(request('status')==='closed')>مغلق (انصرف)</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> تطبيق</button>
                @if(request()->hasAny(['date', 'user_id', 'status']))
                    <a href="{{ route('admin.attendance.index') }}" class="d-block mt-1 small text-muted text-center">
                        مسح الفلاتر
                    </a>
                @endif
            </div>
        </form>
    </x-slot:filters>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="bg-light">
                <tr>
                    <th>الموظف</th>
                    @if($showBranchCol)<th>الفرع</th>@endif
                    <th>التاريخ</th>
                    <th>الحضور</th>
                    <th>الانصراف</th>
                    <th>المدة</th>
                    <th>المصدر</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $a)
                    <tr class="{{ $a->isOpen() ? 'att-row--open' : '' }}">
                        <td>
                            <div class="fw-bold">{{ $a->user->name ?? '—' }}</div>
                            <small class="text-muted">{{ $a->user->username ?? '' }}</small>
                        </td>
                        @if($showBranchCol)
                            <td><x-admin.branch-tag :branch="$a->branch" /></td>
                        @endif
                        <td>{{ $a->clock_in_at->format('Y/m/d') }}</td>
                        <td><span class="att-time">{{ $a->clock_in_at->format('H:i') }}</span></td>
                        <td>
                            @if($a->isOpen())
                                <span class="att-pill att-pill--live">
                                    <span class="att-pulse"></span> حاضر الآن
                                </span>
                            @else
                                <span class="att-time">{{ $a->clock_out_at->format('H:i') }}</span>
                            @endif
                        </td>
                        <td class="fw-bold">{{ $a->durationLabel() }}</td>
                        <td>
                            @switch($a->source)
                                @case('self')           <span class="badge bg-info-transparent">ذاتي</span> @break
                                @case('manager_added')  <span class="badge bg-warning-transparent">يدوي</span> @break
                                @case('auto')           <span class="badge bg-secondary-transparent">تلقائي</span> @break
                            @endswitch
                            @if($a->editedBy)
                                <small class="text-muted d-block">عُدّل: {{ $a->editedBy->name }}</small>
                            @endif
                        </td>
                        <td>
                            <button class="btn btn-sm btn-light"
                                    data-bs-toggle="modal"
                                    data-bs-target="#attEditModal"
                                    data-att-id="{{ $a->id }}"
                                    data-att-user="{{ $a->user_id }}"
                                    data-att-in="{{ $a->clock_in_at->format('Y-m-d\TH:i') }}"
                                    data-att-out="{{ $a->clock_out_at?->format('Y-m-d\TH:i') }}"
                                    data-att-break="{{ $a->break_minutes }}"
                                    data-att-notes="{{ $a->notes }}"
                                    title="تعديل">
                                <i class="bi bi-pencil"></i>
                            </button>
                            @if(auth()->user()->isAdmin() || auth()->user()->isOwnerLevel())
                                <form action="{{ route('admin.attendance.destroy', $a) }}"
                                      method="POST" class="d-inline"
                                      onsubmit="return confirm('حذف هذا السجل؟');">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showBranchCol ? 8 : 7 }}">
                        <x-admin.empty-state icon="bi-clock-history"
                            title="لا توجد سجلات حضور"
                            message="لم يسجّل أي موظف حضوراً بعد لهذا الفرع." />
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($attendances->hasPages())
        <x-slot:footer>{{ $attendances->links() }}</x-slot:footer>
    @endif
</x-admin.data-panel>

{{-- ─── Add modal (manual entry) ─────────────────────────────────── --}}
<div class="modal fade" id="attAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('admin.attendance.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h6 class="modal-title">سجل حضور يدوي</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">الموظف *</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">— اختر —</option>
                            @foreach($staff as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الحضور *</label>
                        <input type="datetime-local" name="clock_in_at" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الانصراف</label>
                        <input type="datetime-local" name="clock_out_at" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">دقائق الاستراحة</label>
                        <input type="number" name="break_minutes" class="form-control" value="0" min="0" max="480">
                    </div>
                    <div class="col-12">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="مثلاً: سجّل الانصراف يدوياً لأنّه نسي الزر…"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ</button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </form>
    </div>
</div>

{{-- ─── Edit modal (populated by JS from data-* on the row's button) ─ --}}
<div class="modal fade" id="attEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="attEditForm" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header">
                <h6 class="modal-title">تعديل سجل الحضور</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label">الموظف *</label>
                        <select name="user_id" class="form-select" required>
                            @foreach($staff as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الحضور *</label>
                        <input type="datetime-local" name="clock_in_at" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">الانصراف</label>
                        <input type="datetime-local" name="clock_out_at" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">دقائق الاستراحة</label>
                        <input type="number" name="break_minutes" class="form-control" min="0" max="480">
                    </div>
                    <div class="col-12">
                        <label class="form-label">ملاحظات</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
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
    .att-row--open { background: rgba(16, 185, 129, .04); }

    .att-time {
        font-family: ui-monospace, monospace;
        font-size: .92rem;
        font-weight: 700;
        color: #1f2937;
    }

    .att-pill {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 3px 9px;
        border-radius: 99px;
        font-size: .72rem; font-weight: 800;
    }
    .att-pill--live {
        background: #ecfdf5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    .att-pulse {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: #10b981;
        position: relative;
    }
    .att-pulse::after {
        content: '';
        position: absolute;
        inset: -3px;
        border-radius: 50%;
        background: rgba(16, 185, 129, .45);
        animation: attPulse 1.6s ease-out infinite;
    }
    @keyframes attPulse {
        0%   { transform: scale(.6); opacity: 1; }
        100% { transform: scale(1.6); opacity: 0; }
    }
</style>
@endpush

@push('scripts')
<script>
/**
 * Wire the row's "edit" button → fill the edit modal from data-* attrs +
 * point the form's action at the right resource. No external library
 * needed — vanilla DOM events are enough here.
 */
(function () {
    const editModal = document.getElementById('attEditModal');
    if (! editModal) return;
    const form = document.getElementById('attEditForm');
    const baseUrl = "{{ url('admin/attendance') }}";

    editModal.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        if (! trigger) return;

        const id     = trigger.dataset.attId;
        const userId = trigger.dataset.attUser;
        const inAt   = trigger.dataset.attIn   || '';
        const outAt  = trigger.dataset.attOut  || '';
        const brk    = trigger.dataset.attBreak || 0;
        const notes  = trigger.dataset.attNotes || '';

        form.action = `${baseUrl}/${id}`;
        form.querySelector('[name="user_id"]').value       = userId;
        form.querySelector('[name="clock_in_at"]').value   = inAt;
        form.querySelector('[name="clock_out_at"]').value  = outAt;
        form.querySelector('[name="break_minutes"]').value = brk;
        form.querySelector('[name="notes"]').value         = notes;
    });
})();
</script>
@endpush
@endsection
