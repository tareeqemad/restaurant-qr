@extends('portal.layout')
@section('title', 'طلباتي')

@section('content')
<div class="pf-section">
    <h1 class="pf-h1">طلباتي السابقة</h1>

    @if($orders->isEmpty())
        <div class="pf-section pf-section--centered">
            <i class="bi bi-bag" style="font-size:3rem;color:#9ca3af;"></i>
            <p class="pf-sub mt-3">لم تطلب من التطبيق بعد.</p>
            <a href="{{ route('portal.order.branches') }}" class="pf-btn pf-btn--primary mt-3">
                <i class="bi bi-bag-plus"></i> اطلب الآن
            </a>
        </div>
    @else
        <div class="poh-list">
            @foreach($orders as $o)
                <a href="{{ route('portal.order.placed', $o) }}" class="poh-row">
                    <div class="poh-row__head">
                        <span class="poh-row__num">{{ $o->number }}</span>
                        <span class="poh-row__type">
                            @if($o->order_type === 'takeaway')
                                <i class="bi bi-bag-fill"></i> استلام
                            @elseif($o->order_type === 'delivery')
                                <i class="bi bi-truck"></i> توصيل
                            @else
                                <i class="bi bi-grid-3x3-gap-fill"></i> داخل المطعم
                            @endif
                        </span>
                        <span class="poh-row__status poh-status--{{ $o->status }}">{{ $o->statusLabel() }}</span>
                    </div>
                    <div class="poh-row__body">
                        <span>{{ $o->branch->name }}</span>
                        <span>·</span>
                        <span>{{ $o->items->count() }} صنف</span>
                        <span>·</span>
                        <span>{{ $o->created_at->diffForHumans() }}</span>
                    </div>
                    <div class="poh-row__total">{{ number_format($o->total, 2) }} ر.ع</div>
                </a>
            @endforeach
        </div>

        <div class="mt-3">{{ $orders->links() }}</div>
    @endif
</div>

@push('styles')
<style>
    .poh-list { display:flex; flex-direction:column; gap:8px; }
    .poh-row {
        display:grid; grid-template-columns: 1fr auto; gap:6px;
        padding:14px 16px; background:#fff; border:1px solid #e5e7eb;
        border-radius:12px; text-decoration:none; color:inherit;
        transition:border-color .15s, transform .15s;
    }
    .poh-row:hover { border-color:rgba(46,125,50,.3); transform:translateY(-1px); }
    .poh-row__head { grid-column:1; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .poh-row__num { font-weight:800; color:var(--ink); font-family:'Menlo','Consolas',monospace; }
    .poh-row__type { font-size:.78rem; padding:2px 8px; background:#f3f4f6; border-radius:6px; }
    .poh-row__status { font-size:.78rem; padding:2px 8px; border-radius:6px; font-weight:700; }
    .poh-status--pending { background:#fef3c7; color:#92400e; }
    .poh-status--approved, .poh-status--preparing, .poh-status--ready, .poh-status--delivered { background:#dbeafe; color:#1e40af; }
    .poh-status--completed { background:#dcfce7; color:#166534; }
    .poh-status--cancelled { background:#fee2e2; color:#991b1b; }
    .poh-row__body { grid-column:1; font-size:.78rem; color:#6b7280; display:flex; gap:6px; flex-wrap:wrap; }
    .poh-row__total { grid-column:2; grid-row:1 / span 2; align-self:center; font-weight:800; color:var(--green-dark); white-space:nowrap; }
</style>
@endpush
@endsection
