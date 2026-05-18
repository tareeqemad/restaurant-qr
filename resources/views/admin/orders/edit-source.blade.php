@extends('layouts.admin')
@section('title', 'تحديد مصدر الطلب')

@section('content')
<x-admin.breadcrumb title="تحديد مصدر الطلب" icon="bi-truck"
    :crumbs="[
        ['label' => 'الطلبات', 'url' => route('admin.orders.index')],
        ['label' => $order->number, 'url' => route('admin.orders.show', $order)],
    ]" />

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.orders.update-source', $order) }}">
                    @csrf

                    <div class="form-section">
                        <div class="form-section-head">
                            <i class="bi bi-truck"></i>
                            <span>منصة التوصيل</span>
                        </div>
                        <div class="row g-3">
                            @foreach(\App\Enums\OrderSource::cases() as $src)
                                <div class="col-md-4 col-6">
                                    <label class="source-card {{ $order->order_source === $src->value ? 'selected' : '' }}"
                                        style="--src-color: {{ $src->color() }};">
                                        <input type="radio" name="order_source" value="{{ $src->value }}"
                                            @checked($order->order_source === $src->value)
                                            data-default-commission="{{ $src->defaultCommission() }}">
                                        <div class="source-card-inner">
                                            <div class="source-card-icon" style="background: {{ $src->color() }};">
                                                <i class="bi {{ $src->icon() }}"></i>
                                            </div>
                                            <div class="source-card-label">{{ $src->label() }}</div>
                                            @if($src->defaultCommission() > 0)
                                                <small class="text-muted">~{{ $src->defaultCommission() }}% عمولة</small>
                                            @endif
                                        </div>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-head">
                            <i class="bi bi-hash"></i>
                            <span>تفاصيل إضافية</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">الجهة المستلمة</label>
                                <input type="text" name="delivery_receiver" value="{{ $order->delivery_receiver }}"
                                    class="form-control" placeholder="اسم المستلم أو شركة التوصيل">
                                <small class="text-muted">اختياري — للطلبات التي يستلمها طرف ثالث</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">رقم مرجعي (اختياري)</label>
                                <input type="text" name="external_reference" value="{{ $order->external_reference }}"
                                    class="form-control" dir="ltr" placeholder="#REF-12345">
                                <small class="text-muted">للاستخدام الداخلي</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="{{ route('admin.orders.show', $order) }}" class="btn btn-light">إلغاء</a>
                        <button class="btn btn-primary"><i class="bi bi-save"></i> حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
.source-card { cursor: pointer; }
.source-card input { display: none; }
.source-card-inner {
    background: white;
    border: 2px solid #e7dfd0;
    border-radius: 14px;
    padding: 1rem;
    text-align: center;
    transition: all .15s;
}
.source-card:hover .source-card-inner { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.08); }
.source-card input:checked + .source-card-inner {
    border-color: var(--src-color);
    background: linear-gradient(180deg, white, color-mix(in srgb, var(--src-color) 8%, white));
    box-shadow: 0 6px 20px color-mix(in srgb, var(--src-color) 25%, transparent);
}
.source-card-icon {
    width: 54px; height: 54px;
    border-radius: 50%;
    color: white;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    margin-bottom: .5rem;
    box-shadow: 0 3px 10px rgba(0,0,0,.15);
}
.source-card-label { font-weight: 900; color: var(--primary); font-size: .95rem; }
</style>
@endpush

@push('scripts')
<script>
// Auto-fill default commission when source is selected
document.querySelectorAll('input[name="order_source"]').forEach(radio => {
    radio.addEventListener('change', (e) => {
        const defaultComm = e.target.dataset.defaultCommission;
        const commInput = document.getElementById('commission-input');
        if (defaultComm && (parseFloat(commInput.value) || 0) === 0) {
            commInput.value = defaultComm;
        }
    });
});
</script>
@endpush
@endsection
