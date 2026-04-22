@extends('layouts.admin')
@section('title', $count->number)

@section('content')
<x-admin.breadcrumb title="{{ $count- />

@php
    $itemsTotal    = $count->items->count();
    $itemsCounted  = $count->items->whereNotNull('counted_qty')->count();
    $variances     = $count->items->filter(fn($i) => $i->hasVariance());
    $varianceCost  = (float) $count->items->sum('variance_cost');
    $shortageCount = $variances->filter(fn($i) => $i->isShort())->count();
    $overCount     = $variances->filter(fn($i) => $i->isOver())->count();
@endphp

<x-admin.stat-rail :stats="[
    ['label' => 'مكونات مُعدّة',    'value' => $itemsCounted.' / '.$itemsTotal,           'icon' => 'bi-list-check',        'color' => 'primary'],
    ['label' => 'نقص (shortage)',   'value' => $shortageCount,                            'icon' => 'bi-arrow-down-circle', 'color' => 'danger'],
    ['label' => 'زيادة (over)',      'value' => $overCount,                                'icon' => 'bi-arrow-up-circle',   'color' => 'accent'],
    ['label' => 'قيمة الفروقات',     'value' => \App\Helpers\Money::format($varianceCost), 'icon' => 'bi-cash-coin',         'color' => abs($varianceCost) > 50 ? 'danger' : 'success'],
]" />

<x-admin.data-panel title="بنود الجرد" :count="$itemsTotal" icon="bi-list-check">
    <x-slot:actions>
        @if($count->isEditable())
            <span class="badge bg-accent" style="background: rgba(var(--accent-rgb),.15); color:#8a6920;">
                <i class="bi bi-pencil"></i> قابل للتعديل
            </span>
        @endif
    </x-slot:actions>

    <form method="POST" action="{{ route('admin.stock-counts.save-counts', $count) }}">
        @csrf
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>المكوّن</th>
                        <th>الوحدة</th>
                        <th>كمية النظام</th>
                        <th style="width:180px;">الكمية الفعلية</th>
                        <th>الفرق</th>
                        <th>قيمة الفرق</th>
                        <th>ملاحظات</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($count->items as $it)
                        @php
                            $hasVar = $it->hasVariance();
                            $varColor = $it->isShort() ? 'danger' : ($it->isOver() ? 'accent' : 'success');
                        @endphp
                        <tr class="{{ $hasVar ? 'table-warning' : '' }}">
                            <td class="fw-bold">{{ $it->ingredient?->name ?? '—' }}</td>
                            <td class="text-muted">{{ $it->ingredient?->baseUnit?->code ?? '' }}</td>
                            <td>{{ number_format((float) $it->system_qty, 4) }}</td>
                            <td>
                                @if($count->isEditable())
                                    <input type="number" step="0.0001" min="0"
                                        name="counts[{{ $it->id }}]"
                                        value="{{ $it->counted_qty }}"
                                        class="form-control"
                                        placeholder="أدخل الكمية">
                                @else
                                    {{ is_null($it->counted_qty) ? '—' : number_format((float) $it->counted_qty, 4) }}
                                @endif
                            </td>
                            <td>
                                @if(is_null($it->counted_qty))
                                    <span class="text-muted">—</span>
                                @elseif($hasVar)
                                    <span class="fw-bold" style="color: {{ $it->isShort() ? '#b91c1c' : 'var(--primary)' }};">
                                        {{ $it->isOver() ? '+' : '' }}{{ number_format((float) $it->variance, 4) }}
                                    </span>
                                @else
                                    <i class="bi bi-check-circle-fill text-success" title="مطابق"></i>
                                @endif
                            </td>
                            <td class="fw-bold" style="color: {{ $it->variance_cost < 0 ? '#b91c1c' : ($it->variance_cost > 0 ? 'var(--primary)' : '#78716c') }};">
                                @if(!is_null($it->counted_qty))
                                    {{ $it->variance_cost >= 0 ? '+' : '' }}{{ number_format((float) $it->variance_cost, 2) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if($count->isEditable())
                                    <input type="text" name="notes[{{ $it->id }}]" value="{{ $it->notes }}" class="form-control form-control-sm" placeholder="...">
                                @else
                                    <small class="text-muted">{{ $it->notes }}</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($count->isEditable())
            <div class="form-actions">
                <a href="{{ route('admin.stock-counts.index') }}" class="btn btn-light">
                    <i class="bi bi-arrow-right"></i> رجوع
                </a>
                <button class="btn btn-primary">
                    <i class="bi bi-save"></i> حفظ الكميات (بدون اعتماد)
                </button>
            </div>
        @endif
    </form>
</x-admin.data-panel>

{{-- Finalize modal --}}
@if($count->isEditable())
    @can('finalize', $count)
    <div class="modal fade" id="finalizeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.stock-counts.finalize', $count) }}">
                    @csrf
                    <div class="modal-header">
                        <h5><i class="bi bi-check-circle-fill"></i> اعتماد الجرد {{ $count->number }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <strong>تحذير:</strong> سيتم تطبيق <strong>{{ $variances->count() }}</strong> تسوية مخزون.
                            قيمة الفروقات الإجمالية: <strong>{{ \App\Helpers\Money::format($varianceCost) }}</strong>.
                            <br>بعد الاعتماد <strong>لن يمكنك التراجع</strong>.
                        </div>

                        @if($itemsCounted < $itemsTotal)
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                هناك <strong>{{ $itemsTotal - $itemsCounted }}</strong> مكوّن لم يُعد (ستبقى كمياتها في النظام بدون تغيير).
                            </div>
                        @endif

                        <ul class="list-unstyled small">
                            @foreach($variances->take(5) as $v)
                                <li class="d-flex justify-content-between py-1 border-bottom">
                                    <span>{{ $v->ingredient?->name }}</span>
                                    <span class="fw-bold" style="color: {{ $v->isShort() ? '#b91c1c' : 'var(--primary)' }};">
                                        {{ $v->isOver() ? '+' : '' }}{{ number_format((float) $v->variance, 2) }} {{ $v->ingredient?->baseUnit?->code }}
                                    </span>
                                </li>
                            @endforeach
                            @if($variances->count() > 5)
                                <li class="text-muted small text-center pt-2">... و {{ $variances->count() - 5 }} تسوية أخرى</li>
                            @endif
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                        <button class="btn btn-success"><i class="bi bi-check-circle-fill"></i> تأكيد الاعتماد</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan
    @can('cancel', $count)
    <div class="modal fade" id="cancelModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.stock-counts.cancel', $count) }}">
                    @csrf
                    <div class="modal-header">
                        <h5>إلغاء الجرد</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">سبب الإلغاء <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button>
                        <button class="btn btn-danger">تأكيد الإلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan
@endif
@endsection
