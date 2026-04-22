@extends('layouts.admin')
@section('title', 'استلام بضاعة: '.$po->number)

@section('content')
<x-admin.breadcrumb title="استلام بضاعة" icon="bi-box-arrow-in-down"
    subtitle="PO #{{ $po->number }} — {{ $po->supplier?->name }}"
    :crumbs="[
        ['label' => 'أوامر الشراء', 'url' => route('admin.purchase-orders.index')],
        ['label' => $po->number, 'url' => route('admin.purchase-orders.show', $po)],
    ]" />

<div class="alert" style="background: rgba(var(--accent-rgb),.08); border-right:4px solid var(--accent); color:var(--primary);">
    <i class="bi bi-info-circle-fill" style="color:var(--accent);"></i>
    أدخل الكمية المستلمة فعلياً لكل بند. سيتم:
    <ul class="mb-0 mt-1">
        <li>إضافة الكمية للمخزون كحركة "إدخال"</li>
        <li>تحديث سعر المكوّن تلقائياً باستخدام المتوسط المرجّح (Weighted Average)</li>
        <li>إعادة حساب تكلفة أصناف المنيو التي تستخدم هذه المكونات</li>
    </ul>
</div>

<form method="POST" action="{{ route('admin.purchase-orders.receive', $po) }}">
    @csrf

    <x-admin.data-panel title="بنود أمر الشراء" :count="$po->items->count()" icon="bi-list-ul">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>المكوّن</th>
                        <th>مطلوب</th>
                        <th>مستلم سابقاً</th>
                        <th>المتبقي</th>
                        <th style="width:200px;">استلام الآن</th>
                        <th>السعر/وحدة</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($po->items as $line)
                        @php $outstanding = $line->outstandingQty(); @endphp
                        <tr class="{{ $line->isFullyReceived() ? 'table-light text-muted' : '' }}">
                            <td>
                                <div class="fw-bold">{{ $line->ingredient?->name ?? '—' }}</div>
                                <small class="text-muted">وحدة القياس: {{ $line->unit?->name }} ({{ $line->unit?->code }})</small>
                            </td>
                            <td>{{ number_format((float)$line->quantity_ordered, 4) }}</td>
                            <td>{{ number_format((float)$line->quantity_received, 4) }}</td>
                            <td class="fw-bold" style="color: {{ $outstanding > 0 ? 'var(--accent-dark)' : 'var(--primary)' }};">
                                {{ number_format($outstanding, 4) }}
                            </td>
                            <td>
                                @if($line->isFullyReceived())
                                    <span class="badge bg-success w-100">
                                        <i class="bi bi-check-circle"></i> مستلم بالكامل
                                    </span>
                                    <input type="hidden" name="receipts[{{ $line->id }}]" value="0">
                                @else
                                    <div class="input-group">
                                        <input type="number" step="0.0001" min="0" max="{{ $outstanding }}"
                                            name="receipts[{{ $line->id }}]"
                                            value="{{ old('receipts.'.$line->id, $outstanding) }}"
                                            class="form-control" placeholder="0">
                                        <span class="input-group-text">{{ $line->unit?->code }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="text-muted">{{ \App\Helpers\Money::format($line->unit_price) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.data-panel>

    <div class="form-actions">
        <a href="{{ route('admin.purchase-orders.show', $po) }}" class="btn btn-light">
            <i class="bi bi-arrow-right"></i> إلغاء
        </a>
        <button class="btn btn-success"
                onclick="return confirm('تأكيد استلام البضاعة؟ سيتم تحديث المخزون والأسعار.');">
            <i class="bi bi-check-circle-fill"></i> تسجيل الاستلام
        </button>
    </div>
</form>
@endsection
