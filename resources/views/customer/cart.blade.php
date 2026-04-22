@extends('customer.layout')
@section('title','السلة')
@section('content')
<div class="p-3">
    <h5 class="fw-bold mb-3"><i class="bi bi-cart3"></i> السلة</h5>

    @if(empty($cart))
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cart-x" style="font-size: 4rem;"></i>
            <p class="mt-3">السلة فارغة</p>
            <a href="{{ route('customer.menu') }}" class="btn btn-danger">تصفح القائمة</a>
        </div>
    @else
        @php $total = collect($cart)->sum('subtotal'); @endphp
        @foreach($cart as $row)
            <div class="menu-card p-3 mb-2">
                <div class="d-flex gap-2">
                    <img src="{{ $row['image'] }}" width="72" height="72" class="rounded" style="object-fit:cover">
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between">
                            <strong>{{ $row['name'] }}</strong>
                            <span class="fw-bold text-danger">{{ \App\Helpers\Money::format($row['subtotal']) }}</span>
                        </div>
                        @if(! empty($row['modifiers']))
                            <small class="text-muted">{{ collect($row['modifiers'])->pluck('name')->join('، ') }}</small>
                        @endif
                        @if(! empty($row['notes']))<small class="d-block text-warning"><i class="bi bi-chat"></i> {{ $row['notes'] }}</small>@endif

                        <div class="d-flex gap-2 mt-2">
                            <form action="{{ route('customer.cart.update') }}" method="POST" class="d-inline">@csrf
                                <input type="hidden" name="row_id" value="{{ $row['id'] }}">
                                <div class="input-group input-group-sm" style="width: 140px;">
                                    <button type="button" onclick="this.nextElementSibling.stepDown();this.form.submit()" class="btn btn-outline-secondary">-</button>
                                    <input name="quantity" type="number" value="{{ $row['quantity'] }}" min="1" class="form-control text-center">
                                    <button type="button" onclick="this.previousElementSibling.stepUp();this.form.submit()" class="btn btn-outline-secondary">+</button>
                                </div>
                            </form>
                            <form action="{{ route('customer.cart.remove') }}" method="POST" class="d-inline">@csrf
                                <input type="hidden" name="row_id" value="{{ $row['id'] }}">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="card mt-3"><div class="card-body">
            <div class="d-flex justify-content-between fs-5 fw-bold mb-2">
                <span>الإجمالي:</span>
                <span class="text-danger">{{ \App\Helpers\Money::format($total) }}</span>
            </div>
            <small class="text-muted d-block">سيتم إضافة الضريبة والخدمة عند الفوترة</small>

            <form action="{{ route('customer.cart.submit') }}" method="POST" class="mt-3">@csrf
                <textarea name="notes" class="form-control mb-2" rows="2" placeholder="ملاحظات عامة للجرسون"></textarea>
                <button class="btn btn-danger btn-lg w-100"><i class="bi bi-send"></i> إرسال الطلب</button>
            </form>
        </div></div>
    @endif
</div>
@endsection
